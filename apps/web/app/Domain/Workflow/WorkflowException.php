<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

final class WorkflowException extends \DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(public readonly string $errorCode, public readonly string $field = 'workflow', public readonly array $replace = [])
    {
        parent::__construct($errorCode);
    }

    public function localized(): string
    {
        $key = 'domain.errors.'.$this->errorCode;
        $message = __($key, $this->replace);

        return is_string($message) && $message !== $key ? $message : $this->errorCode;
    }
}
