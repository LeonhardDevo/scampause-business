<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Risk\Models\RiskSignal;

final class SignalPresenter
{
    /**
     * @return array{code: string, category: string, severity: string, points: int, title: string, explanation: string, advice: string, evidence: array<string, mixed>}
     */
    public static function present(RiskSignal $signal): array
    {
        $code = (string) $signal->getAttribute('code');
        $evidence = $signal->getAttribute('evidence');
        $evidence = is_array($evidence) ? $evidence : [];
        $category = $signal->getAttribute('category');
        $severity = $signal->getAttribute('severity');

        return [
            'code' => $code,
            'category' => $category instanceof \BackedEnum ? (string) $category->value : (string) $category,
            'severity' => $severity instanceof \BackedEnum ? (string) $severity->value : (string) $severity,
            'points' => (int) $signal->getAttribute('points'),
            'title' => self::text($code, 'title', $evidence),
            'explanation' => self::text($code, 'explanation', $evidence),
            'advice' => self::text($code, 'advice', $evidence),
            'evidence' => $evidence,
        ];
    }

    public static function title(string $code): string
    {
        return self::text($code, 'title', []);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function text(string $code, string $part, array $evidence): string
    {
        $replace = [];

        foreach ($evidence as $key => $value) {
            if (is_scalar($value)) {
                $replace[(string) $key] = (string) $value;
            } elseif (is_array($value)) {
                $replace[(string) $key] = implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
            }
        }

        $key = 'risk.signals.'.$code.'.'.$part;
        $text = __($key, $replace);

        return is_string($text) && $text !== $key ? $text : $code;
    }
}
