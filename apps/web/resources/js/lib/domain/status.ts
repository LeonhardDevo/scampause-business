import {
    Ban,
    Banknote,
    CheckCheck,
    FileSearch,
    Loader,
    OctagonX,
    PhoneCall,
    Stamp,
    TimerOff,
} from '@lucide/vue';
import type { Component } from 'vue';
import { humanize, t } from '@/lib/i18n';
import type { PaymentRequestStatus } from '@/types/domain';

export type StatusTone =
    | 'neutral'
    | 'warning'
    | 'info'
    | 'success'
    | 'muted'
    | 'destructive';

export type StatusMeta = {
    icon: Component;
    tone: StatusTone;
    spinning: boolean;
};

export const STATUS_META: Record<PaymentRequestStatus, StatusMeta> = {
    received: { icon: Loader, tone: 'neutral', spinning: true },
    processing: { icon: Loader, tone: 'neutral', spinning: true },
    review: { icon: FileSearch, tone: 'warning', spinning: false },
    awaiting_verification: {
        icon: PhoneCall,
        tone: 'warning',
        spinning: false,
    },
    awaiting_approval: { icon: Stamp, tone: 'info', spinning: false },
    approved: { icon: CheckCheck, tone: 'success', spinning: false },
    paid: { icon: Banknote, tone: 'muted', spinning: false },
    rejected: { icon: OctagonX, tone: 'destructive', spinning: false },
    expired: { icon: TimerOff, tone: 'muted', spinning: false },
    cancelled: { icon: Ban, tone: 'muted', spinning: false },
};

export const OPEN_STATUSES: readonly PaymentRequestStatus[] = [
    'received',
    'processing',
    'review',
    'awaiting_verification',
    'awaiting_approval',
] as const;

export const DECIDED_STATUSES: readonly PaymentRequestStatus[] = [
    'approved',
    'paid',
    'rejected',
    'expired',
    'cancelled',
] as const;

export function isProcessing(status: PaymentRequestStatus): boolean {
    return status === 'received' || status === 'processing';
}

export function isOpen(status: PaymentRequestStatus): boolean {
    return OPEN_STATUSES.includes(status);
}

export function enumLabel(
    group: string,
    value: string | null | undefined,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const key = `domain.enums.${group}.${value}`;
    const label = t(key);

    return label === key ? humanize(value) : label;
}

export function statusLabel(status: PaymentRequestStatus): string {
    return enumLabel('payment_request_status', status);
}

export function roleLabel(role: string | null | undefined): string {
    if (!role) {
        return '—';
    }

    const key = `roles.${role}`;
    const label = t(key);

    return label === key ? humanize(role) : label;
}
