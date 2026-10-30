<?php

declare(strict_types=1);

use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\IntakeService;
use App\Domain\Intake\Models\Extraction;
use App\Enums\WorkspaceRole;
use App\Providers\DomainServiceProvider;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

beforeEach(function (): void {
    Storage::fake('local');
    config(['scampause.fingerprint_key' => base64_encode('test-fingerprint-key')]);
});

/** @return array<string, array{0: string}> */
function spExtractionFixtures(): array
{
    $files = [];

    foreach ([dirname(__DIR__, 2).'/Fixtures/Domain', dirname(__DIR__, 4).'/worker/test/fixtures/domain', dirname(__DIR__, 4).'/worker/tests/fixtures/domain'] as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        foreach ((new Finder)->files()->in($directory)->name('*.extraction.json') as $file) {
            $files[$file->getFilename()] = [(string) $file->getRealPath()];
        }
    }

    return $files;
}

it('ingests every canonical extraction fixture', function (string $path): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Owner);
    $request = app(IntakeService::class)->createFromUpload($workspace, $user, [['bytes' => "%PDF-1.4\n%%EOF", 'name' => 'f.pdf', 'mime' => 'application/pdf']]);
    $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $payload = [...$payload, 'workspace_id' => $workspace->id, 'payment_request_id' => $request->id, 'revision' => 1, 'files' => []];

    $this->internal('POST', "/internal/v1/scampause/payment-requests/{$request->id}/extraction", $payload)->assertOk();
    $this->internal('POST', "/internal/v1/scampause/payment-requests/{$request->id}/assess", ['workspace_id' => $workspace->id, 'revision' => 1])->assertOk();

    $request->refresh();

    expect(Extraction::query()->sole()->status->value)->toBe($payload['status'])
        ->and($request->status)->toBe(PaymentRequestStatus::Review)
        ->and($request->risk_score)->toBeInt();

    if (($payload['fields']['amount_minor']['value'] ?? null) !== null && $payload['status'] !== 'failed') {
        expect($request->amount_minor)->toBe($payload['fields']['amount_minor']['value']);
    }
})->with(spExtractionFixtures());

it('shares the domain props with every page', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Owner);

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('scampause.counts', ['review' => 0, 'awaiting_my_approval' => 0, 'awaiting_verification' => 0])
        ->where('scampause.solo_mode', true)
        ->where('scampause.plan_usage.payment_requests', ['used' => 0, 'limit' => 15])
        ->where('scampause.base_currency', 'USD')
        ->where('can', fn ($can): bool => $can['payment_requests.create'] === true && $can['payment_requests.approve'] === true)
        ->etc());

    $this->member(WorkspaceRole::Approver, $workspace);
    cache()->flush();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('scampause.solo_mode', false)->etc());
});

it('runs the inline pipeline through the dev route only when enabled', function (): void {
    expect(DomainServiceProvider::devRoutesEnabled(true, true))->toBeFalse()
        ->and(DomainServiceProvider::devRoutesEnabled(false, false))->toBeFalse()
        ->and(DomainServiceProvider::devRoutesEnabled(false, true))->toBeTrue()
        ->and(Route::has('dev.scampause.inline-pipeline'))->toBeFalse();

    Route::group([], base_path('routes/dev-domain.php'));
    app('router')->getRoutes()->refreshNameLookups();

    [$user, $workspace] = $this->signInMember();
    $request = app(IntakeService::class)->createFromUpload($workspace, $user, [['bytes' => "%PDF-1.4\n%%EOF", 'name' => 'f.pdf', 'mime' => 'application/pdf']]);
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/Domain/invoice-ru-1c.extraction.json')), true, 512, JSON_THROW_ON_ERROR);

    $this->postJson(route('dev.scampause.inline-pipeline', $request->id), ['extraction' => $fixture])
        ->assertOk()
        ->assertJson(['status' => 'review', 'stages' => ['extract' => ['state' => 'done'], 'bank' => ['state' => 'done'], 'score' => ['state' => 'done']]]);

    expect($request->refresh()->amount_minor)->toBe(18500000);
});
