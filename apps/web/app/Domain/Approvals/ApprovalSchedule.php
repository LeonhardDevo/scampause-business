<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Approvals\Data\ScheduleSettings;
use Carbon\CarbonImmutable;

final class ApprovalSchedule
{
    public static function deadline(CarbonImmutable $start, int $hours, ScheduleSettings $settings): CarbonImmutable
    {
        $at = $start->utc()->addHours($hours);
        $local = $at->setTimezone($settings->timezone);
        $time = $local->format('H:i');
        $isBusinessDay = in_array($local->dayOfWeekIso, $settings->businessDays, true);

        if ($isBusinessDay && $time >= $settings->businessStart && $time < $settings->businessEnd) {
            return $at;
        }

        $day = $isBusinessDay && $time < $settings->businessStart
            ? $local->startOfDay()
            : self::nextBusinessDay($local->startOfDay(), $settings->businessDays);

        $opening = self::at($day, $settings->businessStart, $settings->timezone)->addHours(3);
        $closing = self::at($day, $settings->businessEnd, $settings->timezone)->subHour();

        return ($opening->lessThan($closing) ? $opening : $closing)->utc();
    }

    public static function inQuiet(CarbonImmutable $at, ScheduleSettings $settings): bool
    {
        $time = $at->setTimezone($settings->timezone)->format('H:i');
        $start = $settings->quietStart;
        $end = $settings->quietEnd;

        if ($start === $end) {
            return false;
        }

        if ($start < $end) {
            return $time >= $start && $time < $end;
        }

        return $time >= $start || $time < $end;
    }

    public static function shiftOutOfQuiet(CarbonImmutable $at, ScheduleSettings $settings): CarbonImmutable
    {
        if (! self::inQuiet($at, $settings)) {
            return $at->utc();
        }

        $local = $at->setTimezone($settings->timezone);
        $time = $local->format('H:i');
        $day = $settings->quietStart > $settings->quietEnd && $time >= $settings->quietStart
            ? $local->startOfDay()->addDay()
            : $local->startOfDay();

        return self::at($day, $settings->quietEnd, $settings->timezone)->utc();
    }

    /**
     * @return list<CarbonImmutable>
     */
    public static function reminders(CarbonImmutable $start, CarbonImmutable $expiresAt, ScheduleSettings $settings): array
    {
        $cutoff = $expiresAt->subHour();
        $times = [];

        foreach ($settings->reminderOffsets as $offset) {
            $raw = $start->addHours($offset);

            if (! $raw->lessThan($cutoff)) {
                continue;
            }

            $sendAt = self::shiftOutOfQuiet($raw, $settings);

            if ($sendAt->greaterThanOrEqualTo($cutoff)) {
                continue;
            }

            $times[] = $sendAt;
        }

        $final = self::shiftOutOfQuiet($expiresAt->subHours(2), $settings);
        $last = $times === [] ? null : $times[count($times) - 1];

        if ($final->greaterThan($start) && ($last === null || $final->greaterThan($last->addHour())) && $final->lessThan($expiresAt)) {
            $times[] = $final;
        }

        usort($times, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $times;
    }

    public static function escalation(CarbonImmutable $start, CarbonImmutable $expiresAt, ScheduleSettings $settings): ?CarbonImmutable
    {
        $at = self::shiftOutOfQuiet($start->addHours($settings->escalateAfterHours), $settings);

        return $at->lessThan($expiresAt) ? $at : null;
    }

    /**
     * @param  list<int>  $businessDays
     */
    private static function nextBusinessDay(CarbonImmutable $day, array $businessDays): CarbonImmutable
    {
        $cursor = $day;

        for ($i = 0; $i < 14; $i++) {
            $cursor = $cursor->addDay();

            if (in_array($cursor->dayOfWeekIso, $businessDays, true)) {
                return $cursor;
            }
        }

        return $day->addDay();
    }

    private static function at(CarbonImmutable $day, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($day->setTimezone($timezone)->format('Y-m-d').' '.$time, $timezone);
    }
}
