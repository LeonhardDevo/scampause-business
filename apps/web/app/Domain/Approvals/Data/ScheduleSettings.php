<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Data;

use App\Domain\Policy\Models\WorkspacePaymentSettings;
use App\Models\Workspace;

final readonly class ScheduleSettings
{
    /**
     * @param  list<int>  $businessDays
     * @param  list<int>  $reminderOffsets
     */
    public function __construct(
        public string $timezone = 'UTC',
        public array $businessDays = [1, 2, 3, 4, 5],
        public string $businessStart = '09:00',
        public string $businessEnd = '18:00',
        public string $quietStart = '21:00',
        public string $quietEnd = '08:00',
        public array $reminderOffsets = [4, 24],
        public int $escalateAfterHours = 24,
    ) {}

    public static function from(Workspace $workspace, WorkspacePaymentSettings $settings): self
    {
        $days = $settings->getAttribute('business_days');
        $offsets = $settings->getAttribute('reminder_offsets_hours');

        return new self(
            timezone: $workspace->timezone ?: 'UTC',
            businessDays: is_array($days) ? array_values(array_map('intval', $days)) : [1, 2, 3, 4, 5],
            businessStart: self::time($settings->getAttribute('business_hours_start'), '09:00'),
            businessEnd: self::time($settings->getAttribute('business_hours_end'), '18:00'),
            quietStart: self::time($settings->getAttribute('quiet_hours_start'), '21:00'),
            quietEnd: self::time($settings->getAttribute('quiet_hours_end'), '08:00'),
            reminderOffsets: is_array($offsets) ? array_values(array_map('intval', $offsets)) : [4, 24],
            escalateAfterHours: (int) $settings->getAttribute('escalate_after_hours'),
        );
    }

    public static function time(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^(\d{2}):(\d{2})/', $value, $m) === 1 ? $m[1].':'.$m[2] : $default;
    }
}
