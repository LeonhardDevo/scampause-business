<?php

declare(strict_types=1);

use App\Domain\Support\Concerns\EnumHelpers;
use Symfony\Component\Finder\Finder;

function spForbiddenHits(string $text): array
{
    $hits = [];
    $negations = (array) config('scampause.copy.negations');
    $window = (int) config('scampause.copy.negation_window', 40);
    $lower = mb_strtolower($text, 'UTF-8');

    foreach ((array) config('scampause.copy.forbidden') as $phrases) {
        foreach ((array) $phrases as $phrase) {
            $offset = 0;

            while (($position = mb_strpos($lower, mb_strtolower((string) $phrase, 'UTF-8'), $offset, 'UTF-8')) !== false) {
                $before = mb_substr($lower, max(0, $position - $window), min($window, $position), 'UTF-8');
                $negated = false;

                foreach ($negations as $negation) {
                    if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote((string) $negation, '/').'(?![\p{L}\p{N}])/u', $before) === 1) {
                        $negated = true;
                    }
                }

                if (! $negated) {
                    $hits[] = $phrase;
                }

                $offset = $position + 1;
            }
        }
    }

    return $hits;
}

function spFlatten(mixed $value): string
{
    if (is_array($value)) {
        return implode("\n", array_map(spFlatten(...), $value));
    }

    return is_scalar($value) ? (string) $value : '';
}

it('keeps the domain and risk copy free of affirmative safety claims', function (string $locale, string $file): void {
    $strings = require lang_path($locale.'/'.$file.'.php');

    expect(spForbiddenHits(spFlatten($strings)))->toBe([]);
})->with([['en', 'domain'], ['ru', 'domain'], ['en', 'risk'], ['ru', 'risk']]);

it('detects affirmative phrases and honours negations', function (): void {
    expect(spForbiddenHits('This payment is safe.'))->toBe(['payment is safe'])
        ->and(spForbiddenHits('This does not mean the payment is safe.'))->toBe([])
        ->and(spForbiddenHits('Платёж безопасен.'))->toBe(['платёж безопасен'])
        ->and(spForbiddenHits('Оценка не гарантирует, что платёж безопасен.'))->toBe([]);
});

it('ships no emoji in domain strings and fixtures', function (): void {
    $files = [lang_path('en/domain.php'), lang_path('ru/domain.php'), lang_path('en/risk.php'), lang_path('ru/risk.php'), config_path('scampause_lexicon.php')];

    foreach ((new Finder)->files()->in(base_path('tests/Fixtures/Domain')) as $fixture) {
        $files[] = $fixture->getRealPath();
    }

    foreach ($files as $file) {
        $content = (string) file_get_contents((string) $file);

        expect(preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $content))->toBe(0, (string) $file);
    }
});

it('labels every domain enum value in both locales', function (): void {
    foreach ((new Finder)->files()->in(app_path('Domain'))->path('Enums')->name('*.php') as $file) {
        $class = 'App\\Domain\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! enum_exists($class) || ! in_array(EnumHelpers::class, class_uses($class), true)) {
            continue;
        }

        foreach ($class::cases() as $case) {
            foreach (['en', 'ru'] as $locale) {
                $key = 'domain.enums.'.$class::labelGroup().'.'.$case->value;

                expect(trans($key, [], $locale))->not->toBe($key, $locale.' '.$key);
            }
        }
    }
});

it('mirrors the worker lexicon export', function (): void {
    $path = base_path('../worker/src/domain/lexicon.json');

    if (! is_file($path)) {
        $this->markTestSkipped('Worker lexicon export is not present in this checkout.');
    }

    $worker = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect(config('scampause_lexicon.version'))->toBe($worker['version'])
        ->and(config('scampause_lexicon.categories'))->toBe($worker['categories'])
        ->and(config('scampause_lexicon.content_points_cap'))->toBe($worker['content_points_cap'])
        ->and(config('scampause.bank_change_signals'))->toBe($worker['bank_change_signals'])
        ->and(config('scampause.web_only_signals'))->toBe($worker['web_only_signals']);

    foreach ($worker['categories'] as $code => $category) {
        expect(config('scampause.risk.weights.'.$code))->toBe($category['points']);
    }
});

it('mirrors the worker forbidden-copy export', function (): void {
    $path = base_path('../worker/src/domain/forbidden-copy.json');

    if (! is_file($path)) {
        $this->markTestSkipped('Worker forbidden-copy export is not present in this checkout.');
    }

    $worker = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect(config('scampause.copy.forbidden'))->toBe($worker['phrases'])
        ->and(config('scampause.copy.negations'))->toBe($worker['negations'])
        ->and(config('scampause.copy.negation_window'))->toBe($worker['negation_window'])
        ->and(config('scampause.copy.context_required'))->toBe($worker['context_required']);
});
