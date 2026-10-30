<?php

declare(strict_types=1);

namespace App\Domain\Evidence;

use stdClass;

final class CanonicalJson
{
    private const int FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return json_encode($value, self::FLAGS);
        }

        if (is_float($value)) {
            throw new \InvalidArgumentException('Floats are not allowed in canonical JSON.');
        }

        if (is_string($value)) {
            return json_encode($value, self::FLAGS);
        }

        if ($value instanceof \BackedEnum) {
            return self::encode($value->value);
        }

        if ($value instanceof stdClass) {
            return self::object(get_object_vars($value));
        }

        if (is_array($value)) {
            return array_is_list($value) && $value !== []
                ? '['.implode(',', array_map(self::encode(...), $value)).']'
                : ($value === [] ? '[]' : self::object($value));
        }

        throw new \InvalidArgumentException('Unsupported value in canonical JSON.');
    }

    /** @param  array<array-key, mixed>  $values */
    public static function object(array $values): string
    {
        $keys = array_map('strval', array_keys($values));
        $byKey = array_combine($keys, array_values($values));
        ksort($byKey, SORT_STRING);
        $parts = [];

        foreach ($byKey as $key => $item) {
            $parts[] = json_encode((string) $key, self::FLAGS).':'.self::encode($item);
        }

        return '{'.implode(',', $parts).'}';
    }
}
