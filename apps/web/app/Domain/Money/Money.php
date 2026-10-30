<?php

declare(strict_types=1);

namespace App\Domain\Money;

final readonly class Money
{
    public string $currency;

    public function __construct(public int $minor, string $currency)
    {
        $this->currency = strtoupper($currency);
    }

    public static function of(int $minor, string $currency): self
    {
        return new self($minor, $currency);
    }

    public static function parse(string $major, string $currency): self
    {
        $minor = MoneyParser::toMinor($major, $currency);

        if ($minor === null) {
            throw new \InvalidArgumentException('Invalid money amount.');
        }

        return new self($minor, $currency);
    }

    public function exponent(): int
    {
        return Currencies::exponent($this->currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function isMultipleOfMajor(int $majorUnits): bool
    {
        $unit = $majorUnits * (10 ** $this->exponent());

        return $unit > 0 && $this->minor % $unit === 0;
    }

    public function format(string $locale): string
    {
        return MoneyFormatter::format($this->minor, $this->currency, $locale);
    }

    /** @return array{minor: int, currency: string, formatted: string} */
    public function toDto(string $locale): array
    {
        return ['minor' => $this->minor, 'currency' => $this->currency, 'formatted' => $this->format($locale)];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new \InvalidArgumentException('Currency mismatch.');
        }
    }
}
