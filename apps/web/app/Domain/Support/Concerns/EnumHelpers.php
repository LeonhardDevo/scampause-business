<?php

declare(strict_types=1);

namespace App\Domain\Support\Concerns;

use Illuminate\Support\Str;

trait EnumHelpers
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function labelGroup(): string
    {
        return Str::snake(class_basename(self::class));
    }

    public function label(?string $locale = null): string
    {
        $key = 'domain.enums.'.self::labelGroup().'.'.$this->value;
        $label = __($key, [], $locale);

        return is_string($label) && $label !== $key ? $label : Str::headline($this->value);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    public static function checkConstraint(string $column): string
    {
        $quoted = array_map(static fn (string $value): string => "'".$value."'", self::values());

        return $column.' IN ('.implode(', ', $quoted).')';
    }
}
