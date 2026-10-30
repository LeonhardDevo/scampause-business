<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\OutboxMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('documents');
});

function pdfUpload(string $name = 'policy.pdf'): UploadedFile
{
    $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    return UploadedFile::fake()->createWithContent($name, $pdf);
}

function docxUpload(?string $padding = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hi</w:t></w:r></w:p></w:body></w:document>');
    if ($padding !== null) {
        $zip->addFromString('word/media/padding.bin', $padding);
    }
    $zip->close();

    return UploadedFile::fake()->createWithContent('handbook.docx', (string) file_get_contents($path));
}

it('lists documents with filters, limits and upload constraints', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Member);
    Document::factory()->indexed()->create(['workspace_id' => $workspace->id, 'title' => 'Refund policy', 'department' => 'Finance']);
    Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Travel rules', 'department' => 'HR']);
    Document::factory()->create(['title' => 'Foreign doc']);

    $this->get(route('knowledge.index', ['q' => 'refund']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('knowledge/Index', false)
            ->has('documents.data', 1)
            ->where('documents.data.0.title', 'Refund policy')
            ->where('documents.data.0.status', 'indexed')
            ->has('documents.links')
            ->where('documents.meta.total', 1)
            ->where('filters', ['q' => 'refund'])
            ->where('departments', ['Finance', 'HR'])
            ->has('roles', 6)
            ->where('limits', ['used' => 2, 'limit' => 3])
            ->where('accept', ['.pdf', '.docx', '.txt', '.md', '.markdown', '.html', '.htm', '.png', '.jpg', '.jpeg'])
            ->where('maxSizeMb', 20));

    $this->get(route('knowledge.index', ['status' => 'queued', 'department' => 'HR']))
        ->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.title', 'Travel rules'));
});

it('uploads to the private disk, queues ingestion via the outbox and audits', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Admin);

    $this->post(route('knowledge.store'), [
        'file' => pdfUpload(),
        'title' => 'Expense policy',
        'department' => 'Finance',
        'roles' => ['admin', 'member'],
        'tags' => ['policy', 'money'],
        'version' => '2.1',
        'effective_date' => '2026-01-01',
    ])->assertRedirect()->assertSessionHas('success');

    $document = Document::query()->sole();

    expect($document->workspace_id)->toBe($workspace->id)
        ->and($document->uploaded_by)->toBe($user->id)
        ->and($document->title)->toBe('Expense policy')
        ->and($document->mime)->toBe('application/pdf')
        ->and($document->status)->toBe(DocumentStatus::Queued)
        ->and($document->roles)->toBe(['admin', 'member'])
        ->and($document->sha256)->toHaveLength(64)
        ->and($document->path)->toStartWith($workspace->id.'/');

    Storage::disk('documents')->assertExists($document->path);

    $message = OutboxMessage::query()->topic('document.uploaded')->sole();
    expect($message->workspace_id)->toBe($workspace->id)
        ->and($message->payload)->toEqual([
            'document_id' => $document->id,
            'workspace_id' => $workspace->id,
            'title' => 'Expense policy',
            'mime' => 'application/pdf',
            'ai_external_allowed' => false,
            'metadata' => [
                'department' => 'Finance',
                'roles' => ['admin', 'member'],
                'tags' => ['policy', 'money'],
                'version' => '2.1',
                'effective_date' => '2026-01-01',
            ],
        ]);

    expect(AuditLog::query()->where('action', 'document.uploaded')->value('subject_label'))->toBe('Expense policy');
});

it('accepts docx, markdown and text, defaulting the title to the file name', function (): void {
    $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('knowledge.store'), ['file' => docxUpload()])->assertSessionHasNoErrors();
    $this->post(route('knowledge.store'), ['file' => UploadedFile::fake()->createWithContent('Notes.md', "# Заголовок\n\nТекст правил.")])->assertSessionHasNoErrors();
    $this->post(route('knowledge.store'), ['file' => UploadedFile::fake()->createWithContent('faq.txt', 'Plain text FAQ')])->assertSessionHasNoErrors();

    expect(Document::query()->orderBy('id')->get()->map(fn (Document $d): array => [$d->title, $d->mime])->all())->toBe([
        ['handbook', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ['Notes', 'text/markdown'],
        ['faq', 'text/plain'],
    ]);
});

it('rejects disallowed types and content that does not match the extension', function (UploadedFile $file): void {
    $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('knowledge.store'), ['file' => $file])->assertSessionHasErrors('file');

    expect(Document::query()->count())->toBe(0)
        ->and(OutboxMessage::query()->count())->toBe(0);
})->with([
    'executable' => fn () => UploadedFile::fake()->createWithContent('tool.exe', "MZ\x90\x00binary"),
    'php renamed to pdf' => fn () => UploadedFile::fake()->createWithContent('evil.pdf', '<?php echo "pwned";'),
    'zip renamed to docx' => fn () => UploadedFile::fake()->createWithContent('fake.docx', "PK\x03\x04garbage-not-a-zip"),
    'docx zip bomb' => fn () => docxUpload(str_repeat(' ', 30 * 1024 * 1024)),
    'binary renamed to txt' => fn () => UploadedFile::fake()->createWithContent('bin.txt', "abc\x00\x01\x02def"),
    'pdf renamed to png' => fn () => pdfUpload('image.png'),
]);

it('enforces the 20 MB size limit', function (): void {
    $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('knowledge.store'), ['file' => UploadedFile::fake()->create('huge.pdf', 20 * 1024 + 1, 'application/pdf')])
        ->assertSessionHasErrors('file');
});

it('forbids members from uploading', function (): void {
    $this->signInMember(WorkspaceRole::Member);

    $this->post(route('knowledge.store'), ['file' => pdfUpload()])->assertForbidden();
});

it('shows a document with a signed, expiring download link', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Member);
    Storage::disk('documents')->put('d/1.pdf', '%PDF-1.4 test');
    $document = Document::factory()->create(['workspace_id' => $workspace->id, 'path' => 'd/1.pdf', 'original_name' => 'Policy.pdf']);

    $response = $this->get(route('knowledge.show', $document))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('knowledge/Show', false)
            ->where('document.id', $document->id)
            ->where('chunksPreview', []));

    $url = $response->viewData('page')['props']['document']['download_url'];
    expect($url)->toContain('signature=')->toContain('expires=');

    $download = $this->get($url)->assertOk();
    expect($download->headers->get('content-disposition'))->toContain('Policy.pdf')
        ->and($download->headers->get('x-content-type-options'))->toBe('nosniff');

    $this->get(route('knowledge.download', $document))->assertForbidden();
    $this->travel(16)->minutes();
    $this->get($url)->assertForbidden();
});

it('adds signed download links to list rows and edit options to the document page', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    Storage::disk('documents')->put('d/2.pdf', '%PDF-1.4 test');
    $document = Document::factory()->create(['workspace_id' => $workspace->id, 'path' => 'd/2.pdf', 'department' => 'Finance']);

    $index = $this->get(route('knowledge.index'))->assertOk();
    $url = $index->viewData('page')['props']['documents']['data'][0]['download_url'];
    expect($url)->toContain('/knowledge/'.$document->id.'/download')->toContain('signature=');
    $this->get($url)->assertOk();

    $this->get(route('knowledge.show', $document))
        ->assertInertia(fn ($page) => $page
            ->where('departments', ['Finance'])
            ->where('roles.0.value', 'owner')
            ->has('roles', count(WorkspaceRole::cases())));
});

it('previews chunks of indexed documents from the ai service', function (): void {
    Http::fake(['ai.test/v1/documents/*/chunks*' => Http::response(['chunks' => [
        ['page' => 1, 'section_path' => ['Policy', 'Refunds'], 'content' => 'Refunds within 14 days.'],
    ]])]);
    [, $workspace] = $this->signInMember(WorkspaceRole::Member);
    $document = Document::factory()->indexed()->create(['workspace_id' => $workspace->id]);

    $this->get(route('knowledge.show', $document))
        ->assertInertia(fn ($page) => $page->where('chunksPreview', [
            ['page' => 1, 'section' => 'Policy › Refunds', 'content' => 'Refunds within 14 days.'],
        ]));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/documents/'.$document->id.'/chunks')
        && str_contains($request->url(), 'workspace_id='.$workspace->id)
        && $request->hasHeader('X-Signature'));
});

it('updates metadata, re-queues ingestion and audits the diff', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $document = Document::factory()->indexed()->create(['workspace_id' => $workspace->id, 'title' => 'Old']);

    $this->patch(route('knowledge.update', $document), ['title' => 'New', 'roles' => ['admin']])->assertSessionHas('success');

    $document->refresh();
    expect($document->title)->toBe('New')
        ->and($document->roles)->toBe(['admin'])
        ->and($document->status)->toBe(DocumentStatus::Queued)
        ->and(OutboxMessage::query()->topic('document.uploaded')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'document.updated')->sole()->changes)->toHaveKeys(['title', 'roles']);
});

it('deletes the row and file and publishes document.deleted', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    Storage::disk('documents')->put('x/doc.pdf', 'pdf');
    $document = Document::factory()->create(['workspace_id' => $workspace->id, 'path' => 'x/doc.pdf']);

    $this->delete(route('knowledge.destroy', $document))->assertRedirect(route('knowledge.index'));

    expect(Document::query()->count())->toBe(0)
        ->and(OutboxMessage::query()->topic('document.deleted')->sole()->payload)->toEqual(['document_id' => $document->id, 'workspace_id' => $workspace->id])
        ->and(AuditLog::query()->where('action', 'document.deleted')->exists())->toBeTrue();
    Storage::disk('documents')->assertMissing('x/doc.pdf');
});

it('streams the file to the ai service through the signed internal API', function (): void {
    Storage::disk('documents')->put('w/file.pdf', '%PDF-1.4 content');
    $document = Document::factory()->create(['path' => 'w/file.pdf', 'mime' => 'application/pdf']);

    $response = $this->internal('GET', "/internal/v1/documents/{$document->id}/file", service: 'ai')->assertOk();

    expect($response->streamedContent())->toBe('%PDF-1.4 content')
        ->and($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('x-workspace-id'))->toBe((string) $document->workspace_id);

    $this->internal('GET', '/internal/v1/documents/999999/file', service: 'ai')->assertNotFound();
    $this->get("/internal/v1/documents/{$document->id}/file")->assertUnauthorized();
});

it('records ingestion status reported by the ai service', function (): void {
    $document = Document::factory()->create();

    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", ['status' => 'processing'], 'ai')->assertOk();
    expect($document->refresh()->status)->toBe(DocumentStatus::Processing);

    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", [
        'status' => 'indexed', 'pages' => 4, 'chunks' => 17, 'ocr_used' => true,
    ], 'ai')->assertOk()->assertJson(['status' => 'indexed']);

    $document->refresh();
    expect($document->only(['pages', 'chunks', 'ocr_used', 'error']))->toBe(['pages' => 4, 'chunks' => 17, 'ocr_used' => true, 'error' => null])
        ->and($document->indexed_at)->not->toBeNull();

    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", ['status' => 'failed', 'error' => 'OCR failed'], 'worker')->assertOk();
    expect($document->refresh()->error)->toBe('OCR failed');

    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", ['status' => 'done'], 'ai')->assertStatus(422);
});
