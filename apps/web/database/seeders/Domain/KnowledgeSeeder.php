<?php

declare(strict_types=1);

namespace Database\Seeders\Domain;

use App\Knowledge\DocumentService;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

final class KnowledgeSeeder extends Seeder
{
    public function run(DocumentService $documents): void
    {
        $owner = User::query()->where('email', 'demo@'.config('product.slug', 'scampause-business').'.test')->first();

        if ($owner === null) {
            return;
        }

        foreach (['northwind', 'kedr'] as $slug) {
            $workspace = Workspace::query()->where('slug', $slug)->first();

            if ($workspace !== null) {
                self::seedWorkspace($documents, $workspace, $slug, $owner);
            }
        }
    }

    public static function path(): string
    {
        $configured = config('scampause.seed_documents_path');

        return is_string($configured) && $configured !== '' ? $configured : base_path('../../docs/seed-documents');
    }

    public static function seedWorkspace(DocumentService $documents, Workspace $workspace, string $slug, User $uploader): int
    {
        $created = 0;

        foreach (self::documents() as $document) {
            if (! in_array($slug, $document['workspaces'], true)) {
                continue;
            }

            if (Document::query()->where('workspace_id', $workspace->id)->where('original_name', $document['file'])->exists()) {
                continue;
            }

            $temp = tempnam(sys_get_temp_dir(), 'spdoc');

            if ($temp === false) {
                throw new \RuntimeException('Cannot create a temporary file for '.$document['file']);
            }

            file_put_contents($temp, $document['body']);

            try {
                $documents->upload($workspace, $uploader, new UploadedFile($temp, $document['file'], 'text/markdown', null, true), 'text/markdown', $document['metadata']);
                $created++;
            } finally {
                @unlink($temp);
            }
        }

        return $created;
    }

    /**
     * @return list<array{file: string, body: string, workspaces: list<string>, metadata: array{title?: string|null, department?: string|null, roles?: list<string>, tags?: list<string>, version?: string|null, effective_date?: string|null}}>
     */
    public static function documents(): array
    {
        $files = glob(rtrim(self::path(), '/\\').DIRECTORY_SEPARATOR.'*.{en,ru}.md', GLOB_BRACE);
        $files = is_array($files) ? $files : [];
        sort($files);
        $out = [];

        foreach ($files as $path) {
            $raw = str_replace("\r\n", "\n", (string) file_get_contents($path));
            [$front, $body] = self::split($raw);
            $workspaces = self::stringList($front['workspaces'] ?? []);
            $out[] = [
                'file' => basename($path),
                'body' => ltrim($body),
                'workspaces' => $workspaces,
                'metadata' => [
                    'title' => is_string($front['title'] ?? null) ? $front['title'] : null,
                    'department' => is_string($front['department'] ?? null) ? $front['department'] : null,
                    'roles' => self::stringList($front['roles'] ?? []),
                    'tags' => self::stringList($front['tags'] ?? []),
                    'version' => is_scalar($front['version'] ?? null) ? (string) $front['version'] : null,
                    'effective_date' => is_string($front['effective_date'] ?? null) ? $front['effective_date'] : null,
                ],
            ];
        }

        return $out;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function split(string $raw): array
    {
        if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $raw, $match) !== 1) {
            return [[], $raw];
        }

        $front = [];

        foreach (explode("\n", $match[1]) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $pair) !== 1) {
                continue;
            }

            $value = trim($pair[2]);
            $decoded = json_decode($value, true);
            $front[$pair[1]] = json_last_error() === JSON_ERROR_NONE ? $decoded : trim($value, "\"'");
        }

        return [$front, $match[2]];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
