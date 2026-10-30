<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Assistant\Actions\AssistantAction;
use App\Assistant\Actions\AssistantActionContext;
use App\Assistant\Actions\AssistantActionResult;

final readonly class OpenUrlAction implements AssistantAction
{
    public const array PREFIXES = ['/payment-requests', '/approvals', '/vendors', '/blocked-accounts', '/policies', '/reports', '/knowledge', '/dashboard'];

    public function handle(AssistantActionContext $context): AssistantActionResult
    {
        $url = self::safe($context->payload['url'] ?? $context->payload['arg'] ?? null);

        if ($url === null) {
            return AssistantActionResult::failure((string) __('notifications.assistant.action_unknown', [], $context->locale));
        }

        return AssistantActionResult::success($url);
    }

    public static function safe(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || mb_strlen($url) > 200 || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        if (preg_match('#^/[A-Za-z0-9/_\-]*(\?[A-Za-z0-9=&_\-]*)?$#', $url) !== 1) {
            return null;
        }

        foreach (self::PREFIXES as $prefix) {
            if ($url === $prefix || str_starts_with($url, $prefix.'/') || str_starts_with($url, $prefix.'?')) {
                return $url;
            }
        }

        return null;
    }
}
