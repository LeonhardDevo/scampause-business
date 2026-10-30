import { enumLabel, roleLabel } from '@/lib/domain/status';
import { t, tc } from '@/lib/i18n';
import type { RuleDto } from '@/types/domain';

export function ruleConditions(rule: RuleDto): string[] {
    const chips: string[] = [];

    if (rule.min_amount && rule.max_amount) {
        chips.push(
            t('scampause.policies.cond.amount_between', {
                min: rule.min_amount.formatted,
                max: rule.max_amount.formatted,
            }),
        );
    } else if (rule.min_amount) {
        chips.push(
            t('scampause.policies.cond.amount_min', {
                min: rule.min_amount.formatted,
            }),
        );
    } else if (rule.max_amount) {
        chips.push(
            t('scampause.policies.cond.amount_max', {
                max: rule.max_amount.formatted,
            }),
        );
    }

    if (rule.kinds && rule.kinds.length) {
        chips.push(
            t('scampause.policies.cond.kinds', {
                kinds: rule.kinds
                    .map((kind) => enumLabel('payment_request_kind', kind))
                    .join(', '),
            }),
        );
    }

    if (rule.min_risk_level) {
        chips.push(
            t('scampause.policies.cond.risk', {
                level: enumLabel('risk_level', rule.min_risk_level),
            }),
        );
    }

    if (rule.applies_to_new_vendor) {
        chips.push(t('scampause.policies.cond.new_vendor'));
    }

    if (rule.applies_to_bank_change) {
        chips.push(t('scampause.policies.cond.bank_change'));
    }

    if (!chips.length) {
        chips.push(t('scampause.policies.cond.any'));
    }

    return chips;
}

export function ruleRequirements(rule: RuleDto): string[] {
    const chips = [
        tc('scampause.policies.req.approvers', rule.approvals_required),
    ];

    if (rule.required_roles.length) {
        chips.push(
            t('scampause.policies.req.roles', {
                roles: rule.required_roles
                    .map((role) => roleLabel(role))
                    .join(', '),
            }),
        );
    }

    if (rule.verification_required) {
        chips.push(t('scampause.policies.req.verification'));
    }

    if (rule.expiry_hours) {
        chips.push(tc('scampause.policies.req.expiry', rule.expiry_hours));
    }

    return chips;
}

export function moveItem<T>(items: T[], from: number, to: number): T[] {
    if (
        from === to ||
        from < 0 ||
        to < 0 ||
        from >= items.length ||
        to >= items.length
    ) {
        return [...items];
    }

    const next = [...items];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);

    return next;
}

export function parseOffsets(raw: string): number[] | null {
    const parts = raw
        .split(/[\s,;]+/)
        .map((part) => part.trim())
        .filter(Boolean);

    if (!parts.every((part) => /^\d+$/.test(part))) {
        return null;
    }

    const values = parts.map((part) => Number.parseInt(part, 10));

    return [...new Set(values)].sort((a, b) => a - b);
}

export function offsetsValid(offsets: number[], expiryHours: number): boolean {
    return (
        offsets.length <= 4 &&
        offsets.every(
            (value) => value >= 1 && value <= 167 && value < expiryHours,
        )
    );
}
