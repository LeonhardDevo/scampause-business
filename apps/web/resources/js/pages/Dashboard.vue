<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    ArrowRightLeft,
    CircleCheck,
    CircleDashed,
    FileSearch,
    LayoutDashboard,
    PhoneCall,
    ScanSearch,
    ShieldAlert,
    ShieldCheck,
    Stamp,
    Timer,
    X,
} from '@lucide/vue';
import { useStorage } from '@vueuse/core';
import { computed, ref } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ErrorState from '@/components/common/ErrorState.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import ChartCard from '@/components/domain/ChartCard.vue';
import ExhibitCard from '@/components/domain/ExhibitCard.vue';
import MaskedAccount from '@/components/domain/MaskedAccount.vue';
import RiskBadge from '@/components/domain/RiskBadge.vue';
import StatTile from '@/components/domain/StatTile.vue';
import { Button } from '@/components/ui/button';
import { useCan } from '@/composables/useCan';
import { useFormat } from '@/composables/useFormat';
import {
    donutChart,
    horizontalBarsChart,
    moneyColumnsChart,
    monthLabel,
    riskWeeksChart,
    riskWeeksEmpty,
    weekLabel,
} from '@/lib/domain/charts';
import { deadlineLabel, hoursUntil } from '@/lib/domain/deadline';
import { formatMinor } from '@/lib/domain/money';
import { requestPath, sp } from '@/lib/domain/routes';
import { enumLabel } from '@/lib/domain/status';
import { endpoints } from '@/lib/endpoints';
import { activeLocale, t, tc } from '@/lib/i18n';
import type { ChartTheme } from '@/lib/theme';
import type { DashboardProps, PaymentRequestRow } from '@/types/domain';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.nav.dashboard', href: endpoints.dashboard() },
        ],
    },
});

const props = defineProps<Partial<DashboardProps>>();

const page = usePage();
const { can } = useCan();
const fmt = useFormat();

const currency = computed(
    () =>
        props.kpis?.prevented_amount_30d.currency ??
        page.props.workspace?.currency ??
        'USD',
);

const firstName = computed(
    () => page.props.auth.user?.name.split(' ')[0] ?? '',
);

const isEmpty = computed(
    () =>
        props.kpis !== undefined &&
        props.kpis.open_checks === 0 &&
        props.kpis.high_or_critical_30d === 0 &&
        props.kpis.prevented_cases_30d === 0 &&
        !(props.attention ?? []).length &&
        riskWeeksEmpty(props.charts?.risk_by_week ?? []),
);

type Reason = { label: string; tone: string; icon: typeof Stamp };

function reasonFor(row: PaymentRequestRow): Reason {
    if (row.my_pending_approval_id) {
        return {
            label: t('scampause.dashboard.reasons.your_approval'),
            tone: 'bg-info-soft text-info-soft-foreground border-info/30',
            icon: Stamp,
        };
    }

    if (row.status === 'awaiting_verification') {
        return {
            label: t('scampause.dashboard.reasons.callback'),
            tone: 'bg-warning-soft text-warning-soft-foreground border-warning/30',
            icon: PhoneCall,
        };
    }

    const left = hoursUntil(row.expires_at);

    if (row.status === 'awaiting_approval' && left !== null && left < 12) {
        return {
            label: t('scampause.dashboard.reasons.expires', {
                time: deadlineLabel(row.expires_at),
            }),
            tone: 'bg-warning-soft text-warning-soft-foreground border-warning/30',
            icon: Timer,
        };
    }

    if (row.risk?.level === 'critical') {
        return {
            label: t('scampause.dashboard.reasons.critical'),
            tone: 'bg-risk-critical-tint text-risk-critical border-destructive/30',
            icon: ShieldAlert,
        };
    }

    return {
        label: enumLabel('payment_request_status', row.status),
        tone: 'bg-muted text-muted-foreground border-border',
        icon: FileSearch,
    };
}

const money = (minor: number) =>
    formatMinor(minor, currency.value, activeLocale());
const hours = (value: number | null | undefined) =>
    value === null || value === undefined
        ? '—'
        : t('scampause.common.hours_short', {
              value: fmt.value.number(value, { maximumFractionDigits: 1 }),
          });

const retrying = ref(false);

function retryCharts(): void {
    retrying.value = true;
    router.reload({
        only: ['charts'],
        onFinish: () => {
            retrying.value = false;
        },
    });
}

const buildRisk = (theme: ChartTheme) =>
    riskWeeksChart(theme, props.charts?.risk_by_week ?? []);
const buildPrevented = (theme: ChartTheme) =>
    moneyColumnsChart(
        theme,
        (props.charts?.prevented_vs_approved ?? []).map((item) =>
            monthLabel(item.month),
        ),
        [
            {
                name: t('scampause.dashboard.prevented'),
                color: theme.success,
                data: (props.charts?.prevented_vs_approved ?? []).map(
                    (item) => item.prevented_minor,
                ),
            },
            {
                name: t('scampause.dashboard.approved'),
                color: theme.series[0],
                data: (props.charts?.prevented_vs_approved ?? []).map(
                    (item) => item.approved_minor,
                ),
            },
        ],
        currency.value,
    );
const buildSignals = (theme: ChartTheme) =>
    horizontalBarsChart(
        theme,
        (props.charts?.top_signals ?? []).map((item) => ({
            label: item.title,
            value: item.count,
        })),
    );
const buildSla = (theme: ChartTheme) =>
    donutChart(
        theme,
        [
            {
                name: t('scampause.dashboard.sla.on_time'),
                value: props.charts?.decision_sla.on_time ?? 0,
                color: theme.success,
            },
            {
                name: t('scampause.dashboard.sla.expired'),
                value: props.charts?.decision_sla.expired ?? 0,
                color: theme.destructive,
            },
            {
                name: t('scampause.dashboard.sla.pending'),
                value: props.charts?.decision_sla.pending ?? 0,
                color: theme.series[0],
            },
        ],
        t('scampause.dashboard.sla.total'),
    );

const slaTotal = computed(() => {
    const sla = props.charts?.decision_sla;

    return sla ? sla.on_time + sla.expired + sla.pending : 0;
});

const onboardingItems = computed(() => {
    const onboarding = props.onboarding;

    if (!onboarding) {
        return [];
    }

    return [
        {
            key: 'vendors',
            done: onboarding.has_vendors,
            href: sp.vendors.create().url,
        },
        {
            key: 'callback',
            done: onboarding.has_callback_contacts,
            href: sp.vendors.index(),
        },
        { key: 'rules', done: onboarding.has_rules, href: sp.policies.index() },
        {
            key: 'telegram',
            done: onboarding.telegram_linked,
            href: endpoints.settings.integrations.show().url,
        },
        {
            key: 'approver',
            done: onboarding.has_second_approver,
            href: endpoints.settings.members.index().url,
        },
    ];
});

const onboardingDone = computed(
    () => onboardingItems.value.filter((item) => item.done).length,
);

const telegramDismissed = useStorage('sp-telegram-banner-dismissed', false);
const showTelegramBanner = computed(
    () =>
        props.onboarding &&
        !props.onboarding.telegram_linked &&
        can('payment_requests.approve') &&
        !telegramDismissed.value,
);
</script>

<template>
    <Head :title="t('ui.nav.dashboard')" />

    <div
        class="mx-auto flex w-full max-w-[90rem] flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
        data-test="dashboard"
    >
        <PageHeader
            :icon="LayoutDashboard"
            :eyebrow="t('scampause.dashboard.eyebrow')"
            :title="t('scampause.dashboard.title', { name: firstName })"
            :description="
                period
                    ? t('scampause.dashboard.period', {
                          from: fmt.date(period.from),
                          to: fmt.date(period.to),
                      })
                    : null
            "
        >
        </PageHeader>

        <div
            v-if="showTelegramBanner"
            class="flex items-start justify-between gap-3 rounded-lg border border-info/30 bg-info-soft px-4 py-3 text-sm text-info-soft-foreground"
            role="status"
            data-test="telegram-banner"
        >
            <p>
                {{ t('scampause.banners.telegram_body') }}
                <Link
                    :href="endpoints.settings.integrations.show()"
                    class="font-medium underline underline-offset-2"
                    >{{ t('scampause.banners.telegram_action') }}</Link
                >
            </p>
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="t('scampause.common.dismiss')"
                @click="telegramDismissed = true"
            >
                <X aria-hidden="true" />
            </Button>
        </div>

        <div v-if="!kpis" class="rounded-xl border bg-card">
            <ErrorState
                :message="t('scampause.dashboard.load_error')"
                retryable
                :retrying="retrying"
                @retry="router.reload()"
            />
        </div>

        <template v-else>
            <div
                class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6"
                data-test="dashboard-kpis"
            >
                <StatTile
                    :label="t('scampause.dashboard.kpis.open')"
                    :value="fmt.number(kpis.open_checks)"
                    :icon="FileSearch"
                    :href="sp.requests.index({ status: 'open' })"
                />
                <StatTile
                    :label="t('scampause.dashboard.kpis.my_approvals')"
                    :value="fmt.number(kpis.awaiting_my_approval)"
                    :icon="Stamp"
                    :tone="kpis.awaiting_my_approval ? 'info' : 'default'"
                    :href="
                        can('payment_requests.approve')
                            ? sp.approvals.index()
                            : null
                    "
                    test-id="kpi-my-approvals"
                />
                <StatTile
                    :label="t('scampause.dashboard.kpis.verification')"
                    :value="fmt.number(kpis.awaiting_verification)"
                    :icon="PhoneCall"
                    :tone="kpis.awaiting_verification ? 'warning' : 'default'"
                    :href="
                        sp.requests.index({ status: 'awaiting_verification' })
                    "
                />
                <StatTile
                    :label="t('scampause.dashboard.kpis.high_critical')"
                    :value="fmt.number(kpis.high_or_critical_30d)"
                    :icon="ShieldAlert"
                    :tone="kpis.high_or_critical_30d ? 'critical' : 'default'"
                />
                <StatTile
                    :label="t('scampause.dashboard.kpis.prevented')"
                    :value="kpis.prevented_amount_30d.formatted"
                    :icon="ShieldCheck"
                    tone="success"
                    :hint="
                        tc(
                            'scampause.dashboard.kpis.prevented_cases',
                            kpis.prevented_cases_30d,
                        )
                    "
                    test-id="kpi-prevented"
                />
                <StatTile
                    :label="t('scampause.dashboard.kpis.median')"
                    :value="hours(kpis.median_decision_hours_30d)"
                    :icon="Timer"
                    :hint="
                        kpis.verification_compliance_30d !== null
                            ? t('scampause.dashboard.kpis.compliance', {
                                  value: Math.round(
                                      kpis.verification_compliance_30d,
                                  ),
                              })
                            : null
                    "
                />
            </div>

            <div
                v-if="isEmpty"
                class="rounded-xl border bg-card"
                data-test="dashboard-empty"
            >
                <EmptyState
                    :icon="ScanSearch"
                    :title="t('scampause.dashboard.empty_title')"
                    :description="t('scampause.dashboard.empty_body')"
                >
                    <Button v-if="can('payment_requests.create')" as-child>
                        <Link :href="sp.requests.create()">
                            <ScanSearch aria-hidden="true" />
                            {{ t('scampause.dashboard.first_check') }}
                        </Link>
                    </Button>
                </EmptyState>
            </div>

            <div v-else class="grid gap-4 lg:grid-cols-12">
                <ExhibitCard
                    :title="t('scampause.dashboard.attention')"
                    class="lg:col-span-8"
                    data-test="attention"
                >
                    <ul v-if="attention?.length" class="divide-y">
                        <li v-for="row in attention" :key="row.id">
                            <Link
                                :href="requestPath(row.id)"
                                class="-mx-2 flex flex-col gap-2 rounded-md px-2 py-3 transition-colors hover:bg-accent/40 focus-visible:outline-2 focus-visible:outline-ring sm:flex-row sm:items-center sm:justify-between"
                                data-test="attention-item"
                            >
                                <span class="min-w-0">
                                    <span
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="font-mono text-xs font-semibold"
                                            >{{ row.reference }}</span
                                        >
                                        <span
                                            :class="[
                                                'inline-flex h-6 items-center gap-1 rounded-sm border px-2 text-xs font-medium',
                                                reasonFor(row).tone,
                                            ]"
                                        >
                                            <component
                                                :is="reasonFor(row).icon"
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {{ reasonFor(row).label }}
                                        </span>
                                    </span>
                                    <span
                                        class="mt-0.5 block truncate font-medium"
                                        >{{ row.title }}</span
                                    >
                                    <span
                                        class="block truncate text-sm text-muted-foreground"
                                        >{{
                                            row.vendor?.name ??
                                            t('scampause.common.unknown_vendor')
                                        }}</span
                                    >
                                </span>
                                <span class="flex shrink-0 items-center gap-3">
                                    <span class="font-semibold tabular-nums">{{
                                        row.amount?.formatted ?? '—'
                                    }}</span>
                                    <RiskBadge
                                        v-if="row.risk"
                                        :level="row.risk.level"
                                        :score="row.risk.score"
                                        :tooltip="false"
                                    />
                                    <ArrowRight
                                        class="hidden size-4 text-muted-foreground sm:block"
                                        aria-hidden="true"
                                    />
                                </span>
                            </Link>
                        </li>
                    </ul>
                    <EmptyState
                        v-else
                        :icon="CircleCheck"
                        tone="muted"
                        compact
                        :title="t('scampause.dashboard.attention_empty')"
                    />
                </ExhibitCard>

                <ExhibitCard
                    :title="t('scampause.dashboard.bank_changes')"
                    class="lg:col-span-4"
                    data-test="bank-changes"
                >
                    <ul v-if="recent_bank_changes?.length" class="space-y-3">
                        <li
                            v-for="item in recent_bank_changes"
                            :key="item.request.id"
                        >
                            <Link
                                :href="
                                    requestPath(item.request.id) + '?tab=bank'
                                "
                                class="block rounded-lg border p-3 transition-colors hover:bg-accent/40 focus-visible:outline-2 focus-visible:outline-ring"
                            >
                                <span
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span
                                        class="truncate text-sm font-medium"
                                        >{{
                                            item.request.vendor?.name ??
                                            item.request.title
                                        }}</span
                                    >
                                    <span
                                        class="shrink-0 font-mono text-xs whitespace-nowrap text-muted-foreground"
                                        >{{ item.request.reference }}</span
                                    >
                                </span>
                                <span
                                    class="mt-2 flex flex-wrap items-center gap-1.5"
                                >
                                    <MaskedAccount
                                        :masked="item.diff.previous?.masked"
                                        :last4="item.diff.previous?.last4"
                                    />
                                    <ArrowRightLeft
                                        class="size-3.5 text-risk-critical"
                                        :aria-label="
                                            t('scampause.dashboard.changed_to')
                                        "
                                    />
                                    <MaskedAccount
                                        :masked="item.diff.incoming?.masked"
                                        :last4="item.diff.incoming?.last4"
                                        class="bg-risk-critical-tint text-risk-critical"
                                    />
                                </span>
                                <span
                                    class="mt-1.5 block text-xs text-muted-foreground"
                                >
                                    {{
                                        enumLabel(
                                            'payment_request_status',
                                            item.request.status,
                                        )
                                    }}
                                    · {{ fmt.date(item.request.created_at) }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                    <EmptyState
                        v-else
                        :icon="ArrowRightLeft"
                        tone="muted"
                        compact
                        :title="t('scampause.dashboard.bank_changes_empty')"
                    />
                </ExhibitCard>
            </div>

            <div v-if="!charts" class="rounded-xl border bg-card">
                <ErrorState
                    :message="t('scampause.charts.error')"
                    retryable
                    :retrying="retrying"
                    @retry="retryCharts"
                />
            </div>
            <div
                v-else-if="!isEmpty"
                class="grid gap-4 lg:grid-cols-2"
                data-test="dashboard-charts"
            >
                <ChartCard
                    :title="t('scampause.dashboard.charts.risk')"
                    :description="t('scampause.dashboard.charts.risk_body')"
                    :summary="t('scampause.dashboard.charts.risk_summary')"
                    :build="buildRisk"
                    :columns="[
                        t('scampause.charts.week'),
                        t('ui.risk.levels.critical'),
                        t('ui.risk.levels.high'),
                        t('ui.risk.levels.medium'),
                        t('ui.risk.levels.low'),
                    ]"
                    :rows="
                        charts.risk_by_week.map((week) => [
                            weekLabel(week.week),
                            week.critical,
                            week.high,
                            week.medium,
                            week.low,
                        ])
                    "
                    :empty="riskWeeksEmpty(charts.risk_by_week)"
                    test-id="chart-risk"
                />
                <ChartCard
                    :title="t('scampause.dashboard.charts.prevented')"
                    :description="
                        t('scampause.dashboard.charts.prevented_body', {
                            currency,
                        })
                    "
                    :summary="t('scampause.dashboard.charts.prevented_summary')"
                    :build="buildPrevented"
                    :columns="[
                        t('scampause.charts.month'),
                        t('scampause.dashboard.prevented'),
                        t('scampause.dashboard.approved'),
                    ]"
                    :rows="
                        charts.prevented_vs_approved.map((item) => [
                            monthLabel(item.month),
                            money(item.prevented_minor),
                            money(item.approved_minor),
                        ])
                    "
                    :empty="
                        charts.prevented_vs_approved.every(
                            (item) =>
                                item.prevented_minor === 0 &&
                                item.approved_minor === 0,
                        )
                    "
                    test-id="chart-prevented"
                />
                <ChartCard
                    :title="t('scampause.dashboard.charts.signals')"
                    :description="t('scampause.dashboard.charts.signals_body')"
                    :summary="
                        t('scampause.reports.charts.signals_summary', {
                            top: charts.top_signals[0]?.title ?? '—',
                        })
                    "
                    :build="buildSignals"
                    :columns="[
                        t('scampause.charts.signal'),
                        t('scampause.charts.count'),
                    ]"
                    :rows="
                        charts.top_signals.map((item) => [
                            item.title,
                            item.count,
                        ])
                    "
                    :empty="
                        charts.top_signals.every((item) => item.count === 0)
                    "
                    test-id="chart-signals"
                />
                <ChartCard
                    :title="t('scampause.dashboard.charts.sla')"
                    :description="t('scampause.dashboard.charts.sla_body')"
                    :summary="
                        t('scampause.dashboard.charts.sla_summary', {
                            on_time: charts.decision_sla.on_time,
                            expired: charts.decision_sla.expired,
                            pending: charts.decision_sla.pending,
                        })
                    "
                    :build="buildSla"
                    :columns="[
                        t('scampause.charts.state'),
                        t('scampause.charts.count'),
                    ]"
                    :rows="[
                        [
                            t('scampause.dashboard.sla.on_time'),
                            charts.decision_sla.on_time,
                        ],
                        [
                            t('scampause.dashboard.sla.expired'),
                            charts.decision_sla.expired,
                        ],
                        [
                            t('scampause.dashboard.sla.pending'),
                            charts.decision_sla.pending,
                        ],
                    ]"
                    :empty="slaTotal === 0"
                    test-id="chart-sla"
                />
            </div>

            <ExhibitCard
                v-if="
                    onboardingItems.length &&
                    onboardingDone < onboardingItems.length
                "
                :eyebrow="
                    t('scampause.dashboard.onboarding_eyebrow', {
                        done: onboardingDone,
                        total: onboardingItems.length,
                    })
                "
                :title="t('scampause.dashboard.onboarding_title')"
                data-test="onboarding"
            >
                <ul class="grid gap-2 md:grid-cols-2 xl:grid-cols-5">
                    <li v-for="item in onboardingItems" :key="item.key">
                        <Link
                            :href="item.href"
                            :class="[
                                'flex h-full items-start gap-2 rounded-lg border p-3 text-sm transition-colors hover:bg-accent/40 focus-visible:outline-2 focus-visible:outline-ring',
                                item.done ? 'text-muted-foreground' : '',
                            ]"
                            :data-done="item.done"
                        >
                            <CircleCheck
                                v-if="item.done"
                                class="mt-0.5 size-4 shrink-0 text-success"
                                aria-hidden="true"
                            />
                            <CircleDashed
                                v-else
                                class="mt-0.5 size-4 shrink-0 text-hazard-ink"
                                aria-hidden="true"
                            />
                            <span>
                                <span
                                    :class="[
                                        'block font-medium',
                                        item.done ? 'line-through' : '',
                                    ]"
                                    >{{
                                        t(
                                            `scampause.dashboard.onboarding.${item.key}`,
                                        )
                                    }}</span
                                >
                                <span class="sr-only">{{
                                    item.done
                                        ? t('scampause.common.done')
                                        : t('scampause.common.todo')
                                }}</span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </ExhibitCard>
        </template>
    </div>
</template>
