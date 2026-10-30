import {
    Ban,
    Building2,
    ChartNoAxesCombined,
    CreditCard,
    FileSearch,
    Scale,
    ScanSearch,
    Stamp,
    History,
    LayoutDashboard,
    Library,
    MessagesSquare,
    Palette,
    PlugZap,
    ScrollText,
    Settings,
    ShieldCheck,
    ShieldUser,
    SlidersHorizontal,
    UserRound,
    UsersRound,
    Cpu,
} from '@lucide/vue';
import { sp } from '@/lib/domain/routes';
import { endpoints } from '@/lib/endpoints';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavGroup, NavItem } from '@/types/navigation';
import type { DomainShared } from '@/types/domain';
import type { Ability } from '@/types/shared';

export type CanFn = (ability: Ability, ...fallbacks: Ability[]) => boolean;

export function appNavigation(
    can: CanFn,
    hasWorkspace: boolean,
    counts: DomainShared['counts'] | null = null,
): NavGroup[] {
    const groups: NavGroup[] = [];

    if (hasWorkspace) {
        groups.push({
            label: 'ui.nav.group_overview',
            items: [
                {
                    title: 'ui.nav.dashboard',
                    href: endpoints.dashboard(),
                    icon: LayoutDashboard,
                },
            ],
        });

        const checks: NavItem[] = [
            ...(can('payment_requests.create', 'payment_requests.view_all')
                ? [
                      {
                          title: 'scampause.nav.requests',
                          href: sp.requests.index(),
                          icon: FileSearch,
                          matchPrefix: true,
                          badge: counts?.review || null,
                          badgeTone: 'warning' as const,
                      },
                  ]
                : []),
            ...(can('payment_requests.approve')
                ? [
                      {
                          title: 'scampause.nav.approvals',
                          href: sp.approvals.index(),
                          icon: Stamp,
                          badge: counts?.awaiting_my_approval || null,
                          badgeTone: 'info' as const,
                      },
                  ]
                : []),
            ...(can('payment_requests.create')
                ? [
                      {
                          title: 'scampause.nav.new_check',
                          href: sp.requests.create(),
                          icon: ScanSearch,
                      },
                  ]
                : []),
        ];

        if (checks.length) {
            groups.push({ label: 'scampause.nav.group_checks', items: checks });
        }

        if (can('vendors.view')) {
            groups.push({
                label: 'scampause.nav.group_records',
                items: [
                    {
                        title: 'scampause.nav.vendors',
                        href: sp.vendors.index(),
                        icon: Building2,
                        matchPrefix: true,
                    },
                    {
                        title: 'scampause.nav.blocked',
                        href: sp.blocked.index(),
                        icon: Ban,
                    },
                ],
            });
        }

        const control: NavItem[] = [
            ...(can('policies.view')
                ? [
                      {
                          title: 'scampause.nav.policies',
                          href: sp.policies.index(),
                          icon: Scale,
                          matchPrefix: true,
                      },
                  ]
                : []),
            ...(can('reports.view')
                ? [
                      {
                          title: 'scampause.nav.reports',
                          href: sp.reports.index(),
                          icon: ChartNoAxesCombined,
                      },
                  ]
                : []),
            ...(can('audit.view')
                ? [
                      {
                          title: 'ui.nav.audit',
                          href: endpoints.audit.index(),
                          icon: ScrollText,
                      },
                  ]
                : []),
        ];

        if (control.length) {
            groups.push({ label: 'ui.nav.group_control', items: control });
        }

        const assistant: NavItem[] = [
            ...(can('assistant.use')
                ? [
                      {
                          title: 'ui.nav.assistant',
                          href: endpoints.assistant.index(),
                          icon: MessagesSquare,
                      },
                  ]
                : []),
            ...(can('knowledge.view')
                ? [
                      {
                          title: 'ui.nav.knowledge',
                          href: endpoints.knowledge.index(),
                          icon: Library,
                          matchPrefix: true,
                      },
                  ]
                : []),
            ...(can('assistant.history')
                ? [
                      {
                          title: 'ui.nav.history',
                          href: endpoints.assistant.history(),
                          icon: History,
                      },
                  ]
                : []),
        ];

        if (assistant.length) {
            groups.push({ label: 'ui.nav.group_assistant', items: assistant });
        }
    }

    groups.push({
        label: 'ui.nav.group_manage',
        items: [
            {
                title: 'ui.nav.settings',
                href: hasWorkspace
                    ? endpoints.settings.workspace.show()
                    : editProfile(),
                icon: Settings,
                matchPrefix: true,
            },
        ],
    });

    if (can('admin.ai')) {
        groups.push({
            label: 'ui.nav.group_admin',
            items: [
                {
                    title: 'ui.nav.admin_ai',
                    href: endpoints.admin.ai.index(),
                    icon: Cpu,
                    matchPrefix: true,
                },
            ],
        });
    }

    return groups;
}

export function settingsNavigation(
    can: CanFn,
    hasWorkspace: boolean,
): NavGroup[] {
    const account: NavItem[] = [
        {
            title: 'ui.settings.nav.profile',
            href: editProfile(),
            icon: UserRound,
        },
        {
            title: 'ui.settings.nav.security',
            href: editSecurity(),
            icon: ShieldCheck,
        },
        {
            title: 'ui.settings.nav.appearance',
            href: editAppearance(),
            icon: Palette,
        },
        {
            title: 'ui.settings.nav.privacy',
            href: endpoints.privacy.show(),
            icon: ShieldUser,
        },
    ];

    const workspace: NavItem[] = hasWorkspace
        ? [
              {
                  title: 'ui.settings.nav.workspace',
                  href: endpoints.settings.workspace.show(),
                  icon: SlidersHorizontal,
              },
              {
                  title: 'ui.settings.nav.members',
                  href: endpoints.settings.members.index(),
                  icon: UsersRound,
              },
              ...(can('billing.view', 'billing.manage')
                  ? [
                        {
                            title: 'ui.settings.nav.billing',
                            href: endpoints.settings.billing.show(),
                            icon: CreditCard,
                        },
                    ]
                  : []),
              {
                  title: 'ui.settings.nav.integrations',
                  href: endpoints.settings.integrations.show(),
                  icon: PlugZap,
              },
          ]
        : [];

    return [
        ...(workspace.length
            ? [{ label: 'ui.settings.nav.group_workspace', items: workspace }]
            : []),
        { label: 'ui.settings.nav.group_account', items: account },
    ];
}
