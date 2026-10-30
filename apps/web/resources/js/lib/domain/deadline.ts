import { toDate } from '@/lib/format';
import type { DateInput } from '@/lib/format';
import { t } from '@/lib/i18n';

export type DeadlineTone =
    | 'normal'
    | 'warning'
    | 'critical'
    | 'expired'
    | 'none';

const HOUR = 3_600_000;
const MINUTE = 60_000;

export function deadlineTone(
    value: DateInput,
    now: Date = new Date(),
): DeadlineTone {
    const date = toDate(value);

    if (!date) {
        return 'none';
    }

    const left = date.getTime() - now.getTime();

    if (left <= 0) {
        return 'expired';
    }

    if (left < 2 * HOUR) {
        return 'critical';
    }

    if (left < 12 * HOUR) {
        return 'warning';
    }

    return 'normal';
}

export type Remaining = {
    days: number;
    hours: number;
    minutes: number;
    totalMs: number;
};

export function remaining(
    value: DateInput,
    now: Date = new Date(),
): Remaining | null {
    const date = toDate(value);

    if (!date) {
        return null;
    }

    const totalMs = Math.max(0, date.getTime() - now.getTime());
    const days = Math.floor(totalMs / (24 * HOUR));
    const hours = Math.floor((totalMs % (24 * HOUR)) / HOUR);
    const minutes = Math.floor((totalMs % HOUR) / MINUTE);

    return { days, hours, minutes, totalMs };
}

export function hoursUntil(
    value: DateInput,
    now: Date = new Date(),
): number | null {
    const date = toDate(value);

    return date ? (date.getTime() - now.getTime()) / HOUR : null;
}

export function deadlineLabel(
    value: DateInput,
    now: Date = new Date(),
): string {
    const tone = deadlineTone(value, now);
    const left = remaining(value, now);

    if (!left || tone === 'none') {
        return '—';
    }

    if (tone === 'expired') {
        return t('scampause.deadline.expired');
    }

    if (left.days > 0) {
        return t('scampause.deadline.days_hours', {
            days: left.days,
            hours: left.hours,
        });
    }

    if (left.hours > 0) {
        return t('scampause.deadline.hours_minutes', {
            hours: left.hours,
            minutes: left.minutes,
        });
    }

    return t('scampause.deadline.minutes', {
        minutes: Math.max(1, left.minutes),
    });
}

export const DEADLINE_CLASSES: Record<DeadlineTone, string> = {
    normal: 'text-muted-foreground',
    warning: 'text-hazard-ink',
    critical: 'text-destructive',
    expired: 'text-destructive',
    none: 'text-muted-foreground',
};
