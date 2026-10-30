import {
    AtSign,
    Coins,
    Landmark,
    MessageSquareWarning,
    QrCode,
    Workflow,
} from '@lucide/vue';
import type { Component } from 'vue';
import type {
    EvidenceValue,
    RiskSignal,
    SignalCategory,
    SignalSeverity,
} from '@/types/domain';

export const CATEGORY_ORDER: readonly SignalCategory[] = [
    'bank',
    'sender',
    'content',
    'amount',
    'workflow',
    'qr',
] as const;

export const CATEGORY_ICONS: Record<SignalCategory, Component> = {
    bank: Landmark,
    sender: AtSign,
    content: MessageSquareWarning,
    amount: Coins,
    workflow: Workflow,
    qr: QrCode,
};

export const SEVERITY_RANK: Record<SignalSeverity, number> = {
    info: 0,
    low: 1,
    medium: 2,
    high: 3,
    critical: 4,
};

export const BANK_CHANGE_SIGNALS: readonly string[] = [
    'BANK_DETAILS_CHANGED',
    'PAYROLL_ACCOUNT_CHANGE',
    'QR_PAYEE_MISMATCH',
    'BANK_ACCOUNT_SEEN_OTHER_VENDOR',
    'BANK_ACCOUNT_BLOCKLISTED',
    'EMPLOYEE_DENIED_CHANGE',
] as const;

export const FLOOR_LEVELS: Readonly<Record<string, 'high' | 'critical'>> = {
    BANK_DETAILS_CHANGED: 'high',
    PAYROLL_ACCOUNT_CHANGE: 'high',
    QR_PAYEE_MISMATCH: 'high',
    BANK_ACCOUNT_SEEN_OTHER_VENDOR: 'high',
    SENDER_DOMAIN_LOOKALIKE: 'critical',
    BANK_ACCOUNT_BLOCKLISTED: 'critical',
    EMPLOYEE_DENIED_CHANGE: 'critical',
};

export function floorLevel(
    code: string | null | undefined,
): 'high' | 'critical' | null {
    return code ? (FLOOR_LEVELS[code] ?? null) : null;
}

export const WEB_ONLY_SIGNALS: readonly string[] = [
    ...BANK_CHANGE_SIGNALS,
    'BANK_ACCOUNT_FIRST_USE',
    'BANK_ACCOUNT_UNVERIFIED',
    'BANK_HOLDER_MISMATCH',
] as const;

export type SignalGroup = {
    category: SignalCategory;
    signals: RiskSignal[];
    points: number;
    topSeverity: SignalSeverity;
};

export function groupSignals(signals: RiskSignal[]): SignalGroup[] {
    const groups: SignalGroup[] = [];

    for (const category of CATEGORY_ORDER) {
        const items = signals
            .filter((signal) => signal.category === category)
            .sort(
                (a, b) =>
                    b.points - a.points ||
                    SEVERITY_RANK[b.severity] - SEVERITY_RANK[a.severity] ||
                    a.code.localeCompare(b.code),
            );

        if (items.length === 0) {
            continue;
        }

        groups.push({
            category,
            signals: items,
            points: items.reduce((sum, signal) => sum + signal.points, 0),
            topSeverity: items.reduce<SignalSeverity>(
                (top, signal) =>
                    SEVERITY_RANK[signal.severity] > SEVERITY_RANK[top]
                        ? signal.severity
                        : top,
                'info',
            ),
        });
    }

    return groups;
}

function evidenceRecord(
    signal: RiskSignal,
): Record<string, EvidenceValue> | null {
    return Array.isArray(signal.evidence) ? null : signal.evidence;
}

function stringList(value: EvidenceValue | undefined): string[] {
    if (Array.isArray(value)) {
        return value.filter((item): item is string => typeof item === 'string');
    }

    return typeof value === 'string' && value !== '' ? [value] : [];
}

export function signalPhrases(signal: RiskSignal): string[] {
    return stringList(evidenceRecord(signal)?.phrases);
}

export function pressurePhrases(signals: RiskSignal[]): string[] {
    const seen = new Set<string>();
    const phrases: string[] = [];

    for (const signal of signals) {
        for (const phrase of signalPhrases(signal)) {
            const key = phrase.toLocaleLowerCase();

            if (!seen.has(key)) {
                seen.add(key);
                phrases.push(phrase);
            }
        }
    }

    return phrases.sort((a, b) => b.length - a.length);
}

export function messagePhones(signals: RiskSignal[]): string[] {
    const phones = new Set<string>();

    for (const signal of signals) {
        for (const last4 of stringList(evidenceRecord(signal)?.phones_last4)) {
            phones.add(last4);
        }
    }

    return [...phones];
}

export function hasWebOnlySignal(
    codes: Array<string | null | undefined>,
): boolean {
    return codes.some(
        (code) => typeof code === 'string' && WEB_ONLY_SIGNALS.includes(code),
    );
}

export function evidenceEntries(
    signal: RiskSignal,
): Array<{ key: string; value: string }> {
    const record = evidenceRecord(signal);

    if (!record) {
        return [];
    }

    return Object.entries(record)
        .filter(([key]) => key !== 'phrases' && key !== 'advice')
        .map(([key, value]) => ({
            key,
            value: Array.isArray(value)
                ? value.map((item) => String(item)).join(', ')
                : value !== null && typeof value === 'object'
                  ? JSON.stringify(value)
                  : String(value ?? '—'),
        }))
        .filter((entry) => entry.value !== '');
}

export function totalPoints(signals: RiskSignal[]): number {
    return signals.reduce((sum, signal) => sum + signal.points, 0);
}
