<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * @return list<string>
 */
function fcHits(string $text): array
{
    $hits = [];
    $lower = mb_strtolower($text, 'UTF-8');
    $negations = array_map('strval', (array) config('scampause.copy.negations'));
    $window = (int) config('scampause.copy.negation_window', 40);
    $context = ['rejected by', 'отклон'];

    $scan = static function (string $phrase, bool $needsContext) use ($lower, $negations, $window, $context, &$hits): void {
        $offset = 0;
        $needle = mb_strtolower($phrase, 'UTF-8');

        while (($position = mb_strpos($lower, $needle, $offset, 'UTF-8')) !== false) {
            $offset = $position + 1;
            $before = mb_substr($lower, max(0, $position - $window), min($window, $position), 'UTF-8');
            $after = mb_substr($lower, $position, mb_strlen($needle, 'UTF-8') + $window, 'UTF-8');
            $boundaryBefore = $position === 0 || preg_match('/[\p{L}\p{N}]$/u', mb_substr($lower, $position - 1, 1, 'UTF-8')) !== 1;

            if (! $boundaryBefore) {
                continue;
            }

            foreach ($negations as $negation) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($negation, '/').'(?![\p{L}\p{N}])/u', $before) === 1) {
                    continue 2;
                }
            }

            if ($needsContext) {
                foreach ($context as $marker) {
                    if (str_contains($before.$after, $marker)) {
                        continue 2;
                    }
                }
            }

            $hits[] = $phrase;
        }
    };

    foreach ((array) config('scampause.copy.forbidden') as $phrases) {
        foreach ((array) $phrases as $phrase) {
            $scan((string) $phrase, false);
        }
    }

    foreach ((array) config('scampause.copy.context_required') as $phrases) {
        foreach ((array) $phrases as $phrase) {
            $scan((string) $phrase, true);
        }
    }

    return $hits;
}

/**
 * @return array<string, string>
 */
function fcFlattenKeys(mixed $value, string $prefix = ''): array
{
    if (! is_array($value)) {
        return is_scalar($value) ? [$prefix => (string) $value] : [];
    }

    $out = [];

    foreach ($value as $key => $item) {
        $out = [...$out, ...fcFlattenKeys($item, $prefix === '' ? (string) $key : $prefix.'.'.$key)];
    }

    return $out;
}

function fcAllowed(string $key): bool
{
    return str_starts_with($key, 'marketing.security.') || $key === 'ui.assistant.stopped';
}

/**
 * @return array<string, string>
 */
function fcLangStrings(): array
{
    $strings = [];

    foreach (['en', 'ru'] as $locale) {
        foreach ((new Finder)->files()->in(lang_path($locale))->name('*.php') as $file) {
            $group = $file->getBasename('.php');

            foreach (fcFlattenKeys(require $file->getRealPath(), $group) as $key => $text) {
                $strings[$locale.':'.$key] = $text;
            }
        }
    }

    foreach ((new Finder)->files()->in(lang_path())->depth(0)->name('*.json') as $file) {
        $decoded = json_decode((string) file_get_contents($file->getRealPath()), true);

        foreach (is_array($decoded) ? $decoded : [] as $key => $text) {
            if (is_string($text)) {
                $strings[$file->getBasename().':'.$key] = $text;
            }
        }
    }

    return $strings;
}

/**
 * @return list<string>
 */
function fcVueTextNodes(string $source): array
{
    $template = (string) preg_replace('/<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>|<!--.*?-->/su', '', $source);
    preg_match_all('/>([^<>{}]+)</u', $template, $matches);

    return array_values(array_filter(array_map('trim', $matches[1]), static fn (string $text): bool => $text !== ''));
}

it('finds no affirmative safety claims in any translation string', function (): void {
    $violations = [];

    foreach (fcLangStrings() as $key => $text) {
        $group = explode(':', $key, 2)[1] ?? $key;

        if (! fcAllowed($group) && fcHits($text) !== []) {
            $violations[] = $key.' => '.implode(', ', fcHits($text));
        }
    }

    expect($violations)->toBe([]);
});

it('finds no affirmative safety claims in domain e-mail templates', function (): void {
    $violations = [];

    foreach ((new Finder)->files()->in(resource_path('views/emails/domain'))->name('*.blade.php') as $file) {
        $hits = fcHits(strip_tags((string) file_get_contents($file->getRealPath())));

        if ($hits !== []) {
            $violations[] = $file->getRelativePathname().' => '.implode(', ', $hits);
        }
    }

    expect($violations)->toBe([]);
});

it('finds no affirmative safety claims in the text nodes of Vue templates', function (): void {
    $violations = [];

    foreach ((new Finder)->files()->in(resource_path('js'))->name('*.vue') as $file) {
        foreach (fcVueTextNodes((string) file_get_contents($file->getRealPath())) as $text) {
            $hits = fcHits($text);

            if ($hits !== []) {
                $violations[] = $file->getRelativePathname().' => '.implode(', ', $hits);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('requires a rejection context for on hold and stopped wording', function (): void {
    expect(fcHits('The payment is on hold.'))->toBe(['on hold'])
        ->and(fcHits('Payment of $4,000.00 rejected by your team, it was stopped before it happens.'))->toBe([])
        ->and(fcHits('Платёж остановлен.'))->toBe(['остановлен'])
        ->and(fcHits('Платёж отклонён вашей командой и остановлен.'))->toBe([])
        ->and(fcHits('Nothing is guaranteed to be safe; this is not a guarantee.'))->toBe(['guaranteed'])
        ->and(fcHits('The score is not guaranteed.'))->toBe([])
        ->and(fcVueTextNodes('<template><p>{{ t("x") }}</p><span>Fully protected</span></template><script setup>const a = "<b>safe to pay</b>"</script>'))->toBe(['Fully protected']);
});

it('ships no emoji in the demo seed data', function (): void {
    foreach ((new Finder)->files()->in(database_path('seeders'))->name(['*.php', '*.json']) as $file) {
        expect(preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', (string) file_get_contents($file->getRealPath())))->toBe(0, $file->getRelativePathname());
    }
});
