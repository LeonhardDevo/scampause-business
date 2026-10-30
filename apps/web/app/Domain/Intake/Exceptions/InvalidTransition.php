<?php

declare(strict_types=1);

namespace App\Domain\Intake\Exceptions;

final class InvalidTransition extends \DomainException
{
    public function __construct(public readonly string $errorCode = 'invalid_transition', string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public static function between(\BackedEnum $from, \BackedEnum $to): self
    {
        return new self('invalid_transition', sprintf('Transition %s -> %s is not allowed.', (string) $from->value, (string) $to->value));
    }

    public static function staleRevision(): self
    {
        return new self('stale_revision');
    }
}
