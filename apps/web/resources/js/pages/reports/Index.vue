<script setup lang="ts">
import { Head, router, useForm, useHttp, usePoll } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    ChartNoAxesCombined,
    Download,
    FileDown,
    Fingerprint,
    Hourglass,
    Link2,
    Printer,
    RefreshCw,
    ShieldCheck,
    ShieldX,
    Timer,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CopyButton from '@/components/common/CopyButton.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import ChartCard from '@/components/domain/ChartCard.vue';
import ExhibitCard from '@/components/domain/ExhibitCard.vue';
import RequestsTable from '@/components/domain/RequestsTable.vue';
import StatTile from '@/components/domain/StatTile.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useScamPause } from '@/composables/domain/useScamPause';
import { useFormat } from '@/composables/useFormat';
import {
    histogramChart,
    horizontalBarsChart,
    moneyColumnsChart,
    monthLabel,
    riskWeeksChart,
    riskWeeksEmpty,
    weekLabel,
} from '@/lib/domain/charts';
import { formatMinor } from '@/lib/domain/money';
import { sp } from '@/lib/domain/routes';
import { enumLabel } from '@/lib/domain/status';
import { describeHttpError } from '@/lib/http-errors';
import { activeLocale, t, tc } from '@/lib/i18n';
import type { ChartTheme } from '@/lib/theme';
import type {
    ChainVerification,
    ReportPreset,
    ReportsProps,
    SignalCategory,
} from '@/types/domain';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'scampause.nav.reports', href: sp.reports.index() },
        ],
    },
});

const props = defineProps<ReportsProps>();

const fmt = useFormat();
const shared = useScamPause();
const currency = computed(
    () =>
        props.kpis.prevented_amount.currency ||
        shared.value?.base_currency ||
        'USD',
);

const PRESETS: Exclude<ReportPreset, 'custom'>[] = ['30d', '90d', 'ytd', '12m'];
const from = ref(props.period.from);
const to = ref(props.period.to);

watch(
    () => props.period,
    (period) => {
        from.value = period.from;
        to.value = period.to;
    },
);

function visit(query: Record<string, string>): void {
    router.visit(sp.reports.index(query), {
        preserveScroll: true,
        preserveState: true,
    });
}

function applyCustom(): void {
    if (from.value && to.value && from.value <= to.value) {
        visit({ from: from.value, to: to.value });
    }
}

function money(minor: number): string {
    return formatMinor(minor, currency.value, activeLocale());
}

const percent = (value: number | null) =>
    value === null
        ? '—'
        : `${fmt.value.number(value, { maximumFractionDigits: 0 })}%`;
const hours = (value: number | null) =>
    value === null
        ? '—'
        : t('scampause.common.hours_short', {
              value: fmt.value.number(value, { maximumFractionDigits: 1 }),
          });

const CATEGORY_COLOR: Record<SignalCategory, number> = {
    bank: 0,
    sender: 1,
    content: 2,
    amount: 3,
    workflow: 4,
    qr: 5,
};

const signalsEmpty = computed(() =>
    props.series.signals.every((item) => item.count === 0),
);
const preventedEmpty = computed(() =>
    props.series.prevented_by_month.every(
        (item) => item.fraud_minor === 0 && item.duplicate_minor === 0,
    ),
);
const histogramEmpty = computed(() =>
    props.series.decision_time_histogram.every((item) => item.count === 0),
);

const buildChecks = (theme: ChartTheme) =>
    riskWeeksChart(theme, props.series.checks_by_week);
const buildPrevented = (theme: ChartTheme) =>
    moneyColumnsChart(
        theme,
        props.series.prevented_by_month.map((item) => monthLabel(item.month)),
        [
            {
                name: t('scampause.reports.fraud'),
                color: theme.success,
                data: props.series.prevented_by_month.map(
                    (item) => item.fraud_minor,
                ),
                stack: 'p',
            },
            {
                name: t('scampause.reports.duplicate'),
                color: theme.series[5],
                data: props.series.prevented_by_month.map(
                    (item) => item.duplicate_minor,
                ),
                stack: 'p',
            },
        ],
        currency.value,
    );
const buildSignals = (theme: ChartTheme) =>
    horizontalBarsChart(
        theme,
        props.series.signals.map((item) => ({
            label: item.title,
            value: item.count,
            color: theme.series[CATEGORY_COLOR[item.category] ?? 0],
        })),
    );
const buildHistogram = (theme: ChartTheme) =>
    histogramChart(
        theme,
        props.series.decision_time_histogram.map((item) =>
            t(`scampause.reports.buckets.${bucketKey(item.bucket)}`),
        ),
        props.series.decision_time_histogram.map((item) => item.count),
    );

function bucketKey(bucket: string): string {
    return (
        {
            '<4h': 'lt4h',
            '4-24h': 'h4_24',
            '1-2d': 'd1_2',
            '2-3d': 'd2_3',
            '>3d': 'gt3d',
        }[bucket] ?? 'gt3d'
    );
}

const exportForm = useForm({ kind: 'audit_journal', format: 'csv' });

function requestExport(): void {
    exportForm
        .transform((data) => ({
            ...data,
            from: props.period.from,
            to: props.period.to,
        }))
        .post(sp.reports.storeExport().url, { preserveScroll: true });
}

const pending = computed(() =>
    props.exports.some(
        (item) => item.status === 'queued' || item.status === 'generating',
    ),
);
const poll = usePoll(3000, { only: ['exports'] }, { autoStart: false });

watch(
    pending,
    (value) => {
        if (value) {
            poll.start();
        } else {
            poll.stop();
        }
    },
    { immediate: true },
);

function retry(id: number): void {
    router.post(sp.reports.retryExport(id).url, {}, { preserveScroll: true });
}

const http = useHttp<Record<string, never>, ChainVerification>({});
const verification = ref<ChainVerification | null>(null);
const verifyError = ref<string | null>(null);

async function verifyChain(): Promise<void> {
    verifyError.value = null;

    try {
        const result = await http.post(sp.reports.verifyChain().url);
        verification.value = result ?? null;
    } catch (error) {
        verifyError.value =
            describeHttpError(error).message ??
            t('scampause.reports.verify_failed');
    }
}

const chainOk = computed(() => verification.value?.ok ?? props.chain.ok);
const chainAt = computed(
    () => verification.value?.verified_at ?? props.chain.last_verified_at,
);
const noData = computed(() => props.kpis.checks === 0);

function print(): void {
    window.print();
}
</script>

<template>
    <Head :title="t('scampause.reports.title')" />

    <div
        class="mx-auto flex w-full max-w-[90rem] flex-col gap-5 px-4 py-6 sm:px-6 lg:py-8"
        data-test="reports-page"
    >
        <PageHeader
            :icon="ChartNoAxesCombined"
            :eyebrow="t('scampause.reports.eyebrow')"
            :title="t('scampause.reports.title')"
            :description="t('scampause.reports.description')"
        >
            <template #actions>
                <Button variant="outline" data-print-hide @click="print">
                    <Printer aria-hidden="true" />
                    {{ t('scampause.reports.print') }}
                </Button>
            </template>
        </PageHeader>

        <section
            class="flex flex-col gap-3 rounded-xl border bg-card p-4 lg:flex-row lg:items-end lg:justify-between"
            :aria-label="t('scampause.reports.period')"
            data-print-hide
        >
            <div
                class="flex flex-wrap gap-1.5"
                role="group"
                :aria-label="t('scampause.reports.period')"
            >
                <Button
                    v-for="preset in PRESETS"
                    :key="preset"
                    size="sm"
                    :variant="period.preset === preset ? 'default' : 'outline'"
                    :aria-pressed="period.preset === preset"
                    :data-test="`preset-${preset}`"
                    @click="visit({ preset })"
                >
                    {{ t(`scampause.reports.presets.${preset}`) }}
                </Button>
            </div>
            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="applyCustom"
            >
                <div class="grid gap-1">
                    <Label
                        for="report-from"
                        class="text-xs text-muted-foreground"
                        >{{ t('scampause.common.from') }}</Label
                    >
                    <Input
                        id="report-from"
                        v-model="from"
                        type="date"
                        :max="to"
                        class="w-40"
                    />
                </div>
                <div class="grid gap-1">
                    <Label
                        for="report-to"
                        class="text-xs text-muted-foreground"
                        >{{ t('scampause.common.to') }}</Label
                    >
                    <Input
                        id="report-to"
                        v-model="to"
                        type="date"
                        :min="from"
                        class="w-40"
                    />
                </div>
                <Button
                    type="submit"
                    variant="secondary"
                    size="sm"
                    class="h-10"
                    >{{ t('scampause.reports.apply') }}</Button
                >
            </form>
        </section>
        <p class="text-sm text-muted-foreground">
            {{
                t('scampause.reports.period_line', {
                    from: fmt.date(period.from),
                    to: fmt.date(period.to),
                })
            }}
        </p>

        <div
            v-if="noData"
            class="rounded-xl border bg-card"
            data-test="reports-empty"
        >
            <EmptyState
                :icon="ChartNoAxesCombined"
                tone="muted"
                :title="t('scampause.reports.empty_title')"
                :description="t('scampause.reports.empty_body')"
            />
        </div>

        <div
            class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5"
            data-test="report-kpis"
        >
            <StatTile
                :label="t('scampause.reports.kpis.checks')"
                :value="fmt.number(kpis.checks)"
            />
            <StatTile
                :label="t('scampause.reports.kpis.prevented_amount')"
                :value="kpis.prevented_amount.formatted"
                tone="success"
                :icon="ShieldCheck"
                test-id="kpi-prevented-amount"
            />
            <StatTile
                :label="t('scampause.reports.kpis.prevented_cases')"
                :value="fmt.number(kpis.prevented_cases)"
                tone="success"
            />
            <StatTile
                :label="t('scampause.reports.kpis.duplicates')"
                :value="fmt.number(kpis.duplicates_prevented)"
            />
            <StatTile
                :label="t('scampause.reports.kpis.bank_changes')"
                :value="fmt.number(kpis.bank_changes_caught)"
                :icon="ArrowRightLeft"
            />
            <StatTile
                :label="t('scampause.reports.kpis.legit_changes')"
                :value="fmt.number(kpis.legit_changes_verified)"
            />
            <StatTile
                :label="t('scampause.reports.kpis.median_decision')"
                :value="hours(kpis.median_decision_hours)"
                :icon="Timer"
            />
            <StatTile
                :label="t('scampause.reports.kpis.compliance')"
                :value="percent(kpis.verification_compliance)"
            />
            <StatTile
                :label="t('scampause.reports.kpis.expired_rate')"
                :value="percent(kpis.expired_rate)"
                :icon="Hourglass"
            />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <ChartCard
                :title="t('scampause.reports.charts.checks')"
                :summary="
                    tc('scampause.reports.charts.checks_summary', kpis.checks)
                "
                :build="buildChecks"
                :columns="[
                    t('scampause.charts.week'),
                    t('ui.risk.levels.critical'),
                    t('ui.risk.levels.high'),
                    t('ui.risk.levels.medium'),
                    t('ui.risk.levels.low'),
                ]"
                :rows="
                    series.checks_by_week.map((week) => [
                        weekLabel(week.week),
                        week.critical,
                        week.high,
                        week.medium,
                        week.low,
                    ])
                "
                :empty="riskWeeksEmpty(series.checks_by_week)"
                test-id="chart-checks"
            />
            <ChartCard
                :title="t('scampause.reports.charts.prevented')"
                :summary="
                    t('scampause.reports.charts.prevented_summary', {
                        amount: kpis.prevented_amount.formatted,
                    })
                "
                :build="buildPrevented"
                :columns="[
                    t('scampause.charts.month'),
                    t('scampause.reports.fraud'),
                    t('scampause.reports.duplicate'),
                ]"
                :rows="
                    series.prevented_by_month.map((item) => [
                        monthLabel(item.month),
                        money(item.fraud_minor),
                        money(item.duplicate_minor),
                    ])
                "
                :empty="preventedEmpty"
                test-id="chart-prevented"
            />
            <ChartCard
                :title="t('scampause.reports.charts.signals')"
                :summary="
                    t('scampause.reports.charts.signals_summary', {
                        top: series.signals[0]?.title ?? '—',
                    })
                "
                :build="buildSignals"
                :columns="[
                    t('scampause.charts.signal'),
                    t('scampause.charts.count'),
                ]"
                :rows="series.signals.map((item) => [item.title, item.count])"
                :empty="signalsEmpty"
                test-id="chart-signals"
            />
            <ChartCard
                :title="t('scampause.reports.charts.decision_time')"
                :summary="
                    t('scampause.reports.charts.decision_time_summary', {
                        value: hours(kpis.median_decision_hours),
                    })
                "
                :build="buildHistogram"
                :columns="[
                    t('scampause.reports.bucket'),
                    t('scampause.charts.count'),
                ]"
                :rows="
                    series.decision_time_histogram.map((item) => [
                        t(
                            `scampause.reports.buckets.${bucketKey(item.bucket)}`,
                        ),
                        item.count,
                    ])
                "
                :empty="histogramEmpty"
                test-id="chart-decision-time"
            />
        </div>

        <ExhibitCard :title="t('scampause.reports.by_kind')" flush>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        {{
                            t('scampause.reports.by_kind')
                        }}
                    </caption>
                    <thead
                        class="border-b text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th scope="col" class="px-4 py-2.5 font-medium">
                                {{ t('scampause.fields.kind') }}
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2.5 text-right font-medium"
                            >
                                {{ t('scampause.reports.kpis.checks') }}
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2.5 text-right font-medium"
                            >
                                {{
                                    t('scampause.reports.kpis.prevented_amount')
                                }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in series.by_kind" :key="row.kind">
                            <td class="px-4 py-2.5">
                                {{
                                    enumLabel('payment_request_kind', row.kind)
                                }}
                            </td>
                            <td
                                class="px-4 py-2.5 text-right font-mono tabular-nums"
                            >
                                {{ row.count }}
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums">
                                {{ money(row.prevented_minor) }}
                            </td>
                        </tr>
                        <tr v-if="!series.by_kind.length">
                            <td
                                colspan="3"
                                class="px-4 py-6 text-center text-muted-foreground"
                            >
                                {{ t('scampause.reports.empty_title') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </ExhibitCard>

        <ExhibitCard
            :eyebrow="t('scampause.reports.prevented_eyebrow')"
            :title="t('scampause.reports.prevented_title')"
        >
            <RequestsTable
                v-if="prevented_cases.length"
                :rows="prevented_cases"
                :caption="t('scampause.reports.prevented_title')"
                dense
                test-id="prevented-cases"
            />
            <EmptyState
                v-else
                :icon="ShieldCheck"
                tone="muted"
                compact
                :title="t('scampause.reports.prevented_empty')"
            />
        </ExhibitCard>

        <div class="grid gap-4 lg:grid-cols-2" data-print-hide>
            <ExhibitCard
                :eyebrow="t('scampause.reports.exports_eyebrow')"
                :title="t('scampause.reports.exports_title')"
                data-test="exports-card"
            >
                <form
                    v-if="abilities.export && abilities.exports_allowed_by_plan"
                    class="mb-4 flex flex-wrap items-end gap-2"
                    @submit.prevent="requestExport"
                >
                    <div class="grid gap-1">
                        <Label
                            for="export-kind"
                            class="text-xs text-muted-foreground"
                            >{{ t('scampause.reports.export_kind') }}</Label
                        >
                        <Select v-model="exportForm.kind">
                            <SelectTrigger id="export-kind" class="w-52"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="audit_journal">{{
                                    enumLabel('export_kind', 'audit_journal')
                                }}</SelectItem>
                                <SelectItem value="prevented_risk">{{
                                    enumLabel('export_kind', 'prevented_risk')
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-1">
                        <Label
                            for="export-format"
                            class="text-xs text-muted-foreground"
                            >{{ t('scampause.reports.export_format') }}</Label
                        >
                        <Select v-model="exportForm.format">
                            <SelectTrigger id="export-format" class="w-28"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="format in ['csv', 'pdf', 'json']"
                                    :key="format"
                                    :value="format"
                                    >{{
                                        enumLabel('export_format', format)
                                    }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <Button
                        type="submit"
                        :disabled="exportForm.processing"
                        data-test="export-create"
                    >
                        <Spinner v-if="exportForm.processing" />
                        <FileDown v-else aria-hidden="true" />
                        {{ t('scampause.reports.export_create') }}
                    </Button>
                    <p
                        v-if="Object.keys(exportForm.errors).length"
                        class="w-full text-sm text-destructive"
                        role="alert"
                    >
                        {{ Object.values(exportForm.errors)[0] }}
                    </p>
                </form>
                <p
                    v-else-if="abilities.export"
                    class="mb-4 rounded-md border border-info/30 bg-info-soft px-3 py-2 text-sm text-info-soft-foreground"
                >
                    {{ t('scampause.reports.exports_plan') }}
                </p>
                <ul v-if="exports.length" class="divide-y rounded-lg border">
                    <li
                        v-for="item in exports"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm"
                        data-test="export-row"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ enumLabel('export_kind', item.kind) }} ·
                                {{ enumLabel('export_format', item.format) }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                <template
                                    v-if="item.period_from && item.period_to"
                                    >{{ fmt.date(item.period_from) }} —
                                    {{ fmt.date(item.period_to) }} ·
                                </template>
                                {{ fmt.dateTime(item.created_at) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span
                                :class="[
                                    'inline-flex h-6 items-center gap-1 rounded-sm border px-2 text-xs',
                                    item.status === 'ready'
                                        ? 'border-success/30 bg-success-soft text-success-soft-foreground'
                                        : item.status === 'failed'
                                          ? 'border-destructive/30 bg-destructive-soft text-destructive'
                                          : 'bg-muted text-muted-foreground',
                                ]"
                            >
                                <Spinner
                                    v-if="
                                        item.status === 'queued' ||
                                        item.status === 'generating'
                                    "
                                    class="size-3"
                                />
                                {{ enumLabel('export_status', item.status) }}
                            </span>
                            <Button
                                v-if="item.download_url"
                                as="a"
                                :href="item.download_url"
                                variant="outline"
                                size="sm"
                            >
                                <Download aria-hidden="true" />
                                {{ t('scampause.reports.download') }}
                            </Button>
                            <Button
                                v-if="
                                    item.status === 'failed' && abilities.export
                                "
                                variant="ghost"
                                size="sm"
                                @click="retry(item.id)"
                            >
                                <RefreshCw aria-hidden="true" />
                                {{ t('scampause.common.retry') }}
                            </Button>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    {{ t('scampause.reports.exports_empty') }}
                </p>
            </ExhibitCard>

            <ExhibitCard
                :eyebrow="t('scampause.reports.chain_eyebrow')"
                :title="t('scampause.reports.chain_title')"
                :icon="Link2"
                data-test="chain-card"
            >
                <div class="space-y-4 text-sm">
                    <div
                        :class="[
                            'flex items-start gap-2 rounded-md border px-3 py-2',
                            chainOk === true
                                ? 'border-success/30 bg-success-soft text-success-soft-foreground'
                                : chainOk === false
                                  ? 'border-destructive/40 bg-destructive-soft text-destructive'
                                  : 'bg-muted text-muted-foreground',
                        ]"
                        aria-live="polite"
                        data-test="chain-status"
                        :data-ok="
                            chainOk === null ? 'unknown' : String(chainOk)
                        "
                    >
                        <ShieldCheck
                            v-if="chainOk !== false"
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <ShieldX
                            v-else
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            <template v-if="chainOk === true">{{
                                t('scampause.reports.chain_ok', {
                                    at: chainAt ? fmt.dateTime(chainAt) : '—',
                                })
                            }}</template>
                            <template v-else-if="chainOk === false">
                                {{
                                    t('scampause.reports.chain_broken', {
                                        seq:
                                            verification?.first_broken_seq ??
                                            '—',
                                    })
                                }}
                            </template>
                            <template v-else>{{
                                t('scampause.reports.chain_unknown')
                            }}</template>
                        </span>
                    </div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                        <dt class="text-muted-foreground">
                            {{ t('scampause.reports.entries') }}
                        </dt>
                        <dd class="font-mono tabular-nums">
                            {{
                                fmt.number(
                                    verification?.checked ?? chain.entries,
                                )
                            }}
                        </dd>
                        <template v-if="verification">
                            <dt class="text-muted-foreground">
                                {{ t('scampause.reports.head_hash') }}
                            </dt>
                            <dd
                                class="flex min-w-0 items-center gap-1 font-mono text-xs"
                            >
                                <Fingerprint
                                    class="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span
                                    class="truncate"
                                    :title="verification.head_hash"
                                    >{{ verification.head_hash }}</span
                                >
                                <CopyButton
                                    :value="verification.head_hash"
                                    :label="t('scampause.timeline.copy_hash')"
                                />
                            </dd>
                        </template>
                    </dl>
                    <p
                        v-if="chainOk === false"
                        class="text-pretty text-muted-foreground"
                    >
                        {{ t('scampause.reports.chain_guidance') }}
                    </p>
                    <p v-if="verifyError" class="text-destructive" role="alert">
                        {{ verifyError }}
                    </p>
                    <Button
                        v-if="abilities.verify_chain"
                        variant="secondary"
                        :disabled="http.processing"
                        data-test="verify-chain"
                        @click="verifyChain"
                    >
                        <Spinner v-if="http.processing" />
                        <ShieldCheck v-else aria-hidden="true" />
                        {{ t('scampause.reports.verify') }}
                    </Button>
                </div>
            </ExhibitCard>
        </div>
    </div>
</template>
