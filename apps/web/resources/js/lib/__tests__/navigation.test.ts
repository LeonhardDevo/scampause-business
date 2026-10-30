import { describe, expect, it } from 'vitest';
import { appNavigation, settingsNavigation } from '@/lib/navigation';
import { toUrl } from '@/lib/utils';
import type { Ability } from '@/types';

const canWith =
    (granted: Ability[]) =>
    (ability: Ability, ...fallbacks: Ability[]) =>
        [ability, ...fallbacks].some((key) => granted.includes(key));

const urls = (groups: ReturnType<typeof appNavigation>) =>
    groups.flatMap((group) => group.items.map((item) => toUrl(item.href)));

describe('appNavigation', () => {
    it('shows workspace sections according to abilities', () => {
        const member = urls(
            appNavigation(canWith(['assistant.use', 'knowledge.view']), true),
        );

        expect(member).toEqual([
            '/dashboard',
            '/assistant',
            '/knowledge',
            '/settings/workspace',
        ]);
    });

    it('adds history, audit and the AI admin area when allowed', () => {
        const admin = urls(
            appNavigation(
                canWith([
                    'assistant.use',
                    'assistant.history',
                    'knowledge.view',
                    'audit.view',
                    'admin.ai',
                ]),
                true,
            ),
        );

        expect(admin).toEqual([
            '/dashboard',
            '/audit',
            '/assistant',
            '/knowledge',
            '/assistant/history',
            '/settings/workspace',
            '/admin/ai',
        ]);
    });

    it('orders domain sections as checks, records, control and shows badges', () => {
        const groups = appNavigation(
            canWith([
                'payment_requests.create',
                'payment_requests.approve',
                'vendors.view',
                'policies.view',
                'reports.view',
                'audit.view',
                'assistant.use',
            ]),
            true,
            { review: 3, awaiting_my_approval: 2, awaiting_verification: 1 },
        );

        expect(groups.map((group) => group.label)).toEqual([
            'ui.nav.group_overview',
            'scampause.nav.group_checks',
            'scampause.nav.group_records',
            'ui.nav.group_control',
            'ui.nav.group_assistant',
            'ui.nav.group_manage',
        ]);
        expect(urls(groups)).toEqual([
            '/dashboard',
            '/payment-requests',
            '/approvals',
            '/payment-requests/create',
            '/vendors',
            '/blocked-accounts',
            '/policies',
            '/reports',
            '/audit',
            '/assistant',
            '/settings/workspace',
        ]);

        const checks = groups[1].items;

        expect(checks[0]).toMatchObject({ badge: 3, badgeTone: 'warning' });
        expect(checks[1]).toMatchObject({ badge: 2, badgeTone: 'info' });
    });

    it('hides domain sections without permissions and empty badges', () => {
        const groups = appNavigation(
            canWith(['payment_requests.create']),
            true,
            { review: 0, awaiting_my_approval: 0, awaiting_verification: 0 },
        );

        expect(urls(groups)).toEqual([
            '/dashboard',
            '/payment-requests',
            '/payment-requests/create',
            '/settings/workspace',
        ]);
        expect(groups[1].items[0].badge).toBeNull();
    });

    it('groups sections into overview, assistant and control', () => {
        const groups = appNavigation(
            canWith(['assistant.use', 'audit.view']),
            true,
        );

        expect(groups.map((group) => group.label)).toEqual([
            'ui.nav.group_overview',
            'ui.nav.group_control',
            'ui.nav.group_assistant',
            'ui.nav.group_manage',
        ]);
    });

    it('only offers account settings without a workspace', () => {
        const groups = appNavigation(canWith([]), false);

        expect(groups).toHaveLength(1);
        expect(urls(groups)).toEqual(['/settings/profile']);
    });
});

describe('settingsNavigation', () => {
    it('includes billing only for users who can see it', () => {
        const without = settingsNavigation(canWith([]), true).flatMap((group) =>
            group.items.map((item) => toUrl(item.href)),
        );
        const withBilling = settingsNavigation(
            canWith(['billing.view']),
            true,
        ).flatMap((group) => group.items.map((item) => toUrl(item.href)));

        expect(without).not.toContain('/settings/billing');
        expect(withBilling).toContain('/settings/billing');
        expect(withBilling).toContain('/settings/profile');
    });

    it('hides workspace settings without a workspace', () => {
        const groups = settingsNavigation(canWith(['billing.view']), false);

        expect(groups).toHaveLength(1);
        expect(groups[0].label).toBe('ui.settings.nav.group_account');
    });
});
