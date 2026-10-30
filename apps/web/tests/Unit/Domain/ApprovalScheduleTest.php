<?php

declare(strict_types=1);

use App\Domain\Approvals\ApprovalSchedule;
use App\Domain\Approvals\Data\ScheduleSettings;
use Carbon\CarbonImmutable;

function localAt(string $local, string $tz): CarbonImmutable
{
    return CarbonImmutable::parse($local, $tz)->utc();
}

it('rolls a Friday 17:00 Moscow submission with 48 hours to Monday 12:00', function (): void {
    $settings = new ScheduleSettings(timezone: 'Europe/Moscow');
    $deadline = ApprovalSchedule::deadline(localAt('2026-10-23 17:00', 'Europe/Moscow'), 48, $settings);

    expect($deadline->setTimezone('Europe/Moscow')->format('Y-m-d H:i D'))->toBe('2026-10-26 12:00 Mon');
});

it('keeps deadlines inside business hours as they are', function (): void {
    $settings = new ScheduleSettings(timezone: 'Europe/Moscow');
    $deadline = ApprovalSchedule::deadline(localAt('2026-10-20 10:00', 'Europe/Moscow'), 4, $settings);

    expect($deadline->setTimezone('Europe/Moscow')->format('Y-m-d H:i'))->toBe('2026-10-20 14:00');
});

it('rolls an early-morning deadline on a business day to noon the same day', function (): void {
    $settings = new ScheduleSettings(timezone: 'Europe/Moscow');
    $deadline = ApprovalSchedule::deadline(localAt('2026-10-20 05:00', 'Europe/Moscow'), 1, $settings);

    expect($deadline->setTimezone('Europe/Moscow')->format('Y-m-d H:i'))->toBe('2026-10-20 12:00');
});

it('adds hours in UTC across the New York DST change', function (): void {
    $settings = new ScheduleSettings(timezone: 'America/New_York');
    $start = localAt('2026-10-31 10:00', 'America/New_York');
    $deadline = ApprovalSchedule::deadline($start, 48, $settings);

    expect((int) $deadline->diffInHours($start, true))->toBe(48)
        ->and($deadline->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-11-02 09:00');

    $rolled = ApprovalSchedule::deadline(localAt('2026-10-30 17:00', 'America/New_York'), 48, $settings);
    expect($rolled->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-11-02 12:00')
        ->and($rolled->format('H:i'))->toBe('17:00');
});

it('shifts moments out of a quiet window that wraps midnight', function (string $local, string $expected): void {
    $settings = new ScheduleSettings(timezone: 'Europe/Moscow', quietStart: '21:00', quietEnd: '08:00');

    expect(ApprovalSchedule::shiftOutOfQuiet(localAt($local, 'Europe/Moscow'), $settings)->setTimezone('Europe/Moscow')->format('Y-m-d H:i'))->toBe($expected);
})->with([
    'evening' => ['2026-10-20 22:30', '2026-10-21 08:00'],
    'after midnight' => ['2026-10-21 03:00', '2026-10-21 08:00'],
    'outside quiet' => ['2026-10-21 12:00', '2026-10-21 12:00'],
    'start boundary is quiet' => ['2026-10-20 21:00', '2026-10-21 08:00'],
    'end boundary is not quiet' => ['2026-10-21 08:00', '2026-10-21 08:00'],
]);

it('plans reminders, a final reminder and an escalation before expiry', function (): void {
    $settings = new ScheduleSettings(timezone: 'UTC', quietStart: '21:00', quietEnd: '08:00', reminderOffsets: [4, 24], escalateAfterHours: 24);
    $start = CarbonImmutable::parse('2026-10-20 10:00', 'UTC');
    $expires = CarbonImmutable::parse('2026-10-22 10:00', 'UTC');
    $times = array_map(static fn (CarbonImmutable $t): string => $t->format('Y-m-d H:i'), ApprovalSchedule::reminders($start, $expires, $settings));

    expect($times)->toBe(['2026-10-20 14:00', '2026-10-21 10:00', '2026-10-22 08:00'])
        ->and(ApprovalSchedule::escalation($start, $expires, $settings)?->format('Y-m-d H:i'))->toBe('2026-10-21 10:00');
});

it('drops reminders that would land within the last hour and skips escalation after expiry', function (): void {
    $settings = new ScheduleSettings(timezone: 'UTC', quietStart: '00:00', quietEnd: '00:00', reminderOffsets: [4], escalateAfterHours: 24);
    $start = CarbonImmutable::parse('2026-10-20 10:00', 'UTC');
    $expires = $start->addHours(4);

    expect(array_map(static fn (CarbonImmutable $t): string => $t->format('H:i'), ApprovalSchedule::reminders($start, $expires, $settings)))->toBe(['12:00'])
        ->and(ApprovalSchedule::escalation($start, $expires, $settings))->toBeNull();
});
