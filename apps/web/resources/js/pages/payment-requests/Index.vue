<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    FileSearch,
    FilterX,
    Rows3,
    Rows4,
    ScanSearch,
    SearchX,
} from '@lucide/vue';
import { useStorage } from '@vueuse/core';
import { computed } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import RequestsTable from '@/components/domain/RequestsTable.vue';
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
import { Switch } from '@/components/ui/switch';
import { useCan } from '@/composables/useCan';
import { useFilters } from '@/composables/useFilters';
import { sp } from '@/lib/domain/routes';
import type { QueryValue } from '@/lib/endpoints';
import { enumLabel } from '@/lib/domain/status';
import { t, tc } from '@/lib/i18n';
import { RISK_LEVELS } from '@/lib/risk';
import type {
    PaymentRequestIndexProps,
    PaymentRequestKind,
    RequestFilters,
} from '@/types/domain';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'scampause.nav.requests', href: sp.requests.index() },
        ],
    },
});

const props = defineProps<PaymentRequestIndexProps>();

const { can } = useCan();

const initial = computed<RequestFilters>(() =>
    Array.isArray(props.filters) ? {} : props.filters,
);

const startFilters: Record<string, QueryValue> = {
    q: initial.value.q ?? null,
    status: initial.value.status ?? null,
    risk: initial.value.risk ?? null,
    kind: initial.value.kind ?? null,
    vendor_id: initial.value.vendor_id ? String(initial.value.vendor_id) : null,
    mine:
        initial.value.mine === true ||
        initial.value.mine === '1' ||
        initial.value.mine === 'true'
            ? 1
            : null,
    from: initial.value.from ?? null,
    to: initial.value.to ?? null,
};

const { filters, reset, active } = useFilters(
    () => sp.requests.index(),
    startFilters,
    ['requests', 'filters', 'counts'],
);

const KINDS: PaymentRequestKind[] = [
    'invoice',
    'bank_details_change',
    'urgent_wire',
    'payroll_change',
    'qr_payment',
    'other',
];

const statusTabs = computed(() => [
    { value: 'all', label: t('scampause.requests.tabs.all'), count: null },
    {
        value: 'open',
        label: t('scampause.requests.tabs.open'),
        count: props.counts.open,
    },
    {
        value: 'review',
        label: t('scampause.requests.tabs.review'),
        count: props.counts.review,
    },
    {
        value: 'awaiting_verification',
        label: t('scampause.requests.tabs.awaiting_verification'),
        count: props.counts.awaiting_verification,
    },
    {
        value: 'awaiting_approval',
        label: t('scampause.requests.tabs.awaiting_approval'),
        count: props.counts.awaiting_approval,
    },
    {
        value: 'decided',
        label: t('scampause.requests.tabs.decided'),
        count: props.counts.decided,
    },
]);

const statusModel = computed({
    get: () => (filters.status as string | null) ?? 'all',
    set: (value: string) => {
        filters.status = value === 'all' ? null : value;
    },
});

function selectModel(key: 'risk' | 'kind' | 'vendor_id') {
    return computed({
        get: () => (filters[key] as string | null) ?? 'all',
        set: (value: string) => {
            filters[key] = value === 'all' ? null : value;
        },
    });
}

const riskModel = selectModel('risk');
const kindModel = selectModel('kind');
const vendorModel = selectModel('vendor_id');

const mineModel = computed({
    get: () => filters.mine === 1,
    set: (value: boolean) => {
        filters.mine = value ? 1 : null;
    },
});

const dense = useStorage('sp-requests-dense', false);

const total = computed(() => props.requests.meta.total);
const firstRun = computed(() => total.value === 0 && !active());
</script>

<template>
    <Head :title="t('scampause.requests.title')" />

    <div
        class="mx-auto flex w-full max-w-[90rem] flex-col gap-5 px-4 py-6 sm:px-6 lg:py-8"
        data-test="requests-page"
    >
        <PageHeader
            :icon="FileSearch"
            :eyebrow="t('scampause.requests.eyebrow')"
            :title="t('scampause.requests.title')"
            :description="t('scampause.requests.description')"
        >
        </PageHeader>

        <nav
            class="-mx-1 flex scrollbar-thin gap-1 overflow-x-auto px-1 pb-1"
            :aria-label="t('scampause.requests.status_filter')"
        >
            <button
                v-for="tab in statusTabs"
                :key="tab.value"
                type="button"
                :aria-pressed="statusModel === tab.value"
                :class="[
                    'inline-flex h-9 shrink-0 items-center gap-2 rounded-md border px-3 text-sm font-medium whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring pointer-coarse:min-h-11',
                    statusModel === tab.value
                        ? 'border-ring bg-accent text-accent-foreground'
                        : 'bg-card text-muted-foreground hover:bg-muted',
                ]"
                :data-test="`status-tab-${tab.value}`"
                @click="statusModel = tab.value"
            >
                {{ tab.label }}
                <span
                    v-if="tab.count !== null"
                    class="rounded-sm bg-muted px-1.5 font-mono text-xs tabular-nums"
                    >{{ tab.count }}</span
                >
            </button>
        </nav>

        <section
            class="grid grid-cols-1 gap-3 rounded-xl border bg-card p-4 shadow-xs sm:grid-cols-2 lg:grid-cols-4 lg:items-end 2xl:grid-cols-[1.4fr_repeat(3,1fr)_auto_auto]"
            :aria-label="t('scampause.common.filters')"
        >
            <div
                class="grid gap-1.5 sm:col-span-2 lg:col-span-4 2xl:col-span-1"
            >
                <span class="text-xs text-muted-foreground">{{
                    t('scampause.common.search')
                }}</span>
                <SearchInput
                    :model-value="filters.q as string | null"
                    :placeholder="t('scampause.requests.search_placeholder')"
                    :label="t('scampause.common.search')"
                    class="sm:max-w-none"
                    @update:model-value="(value) => (filters.q = value || null)"
                />
            </div>
            <div class="grid gap-1.5">
                <Label
                    for="filter-risk"
                    class="text-xs text-muted-foreground"
                    >{{ t('scampause.fields.risk') }}</Label
                >
                <Select v-model="riskModel">
                    <SelectTrigger id="filter-risk" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{
                            t('scampause.common.any')
                        }}</SelectItem>
                        <SelectItem
                            v-for="level in RISK_LEVELS"
                            :key="level"
                            :value="level"
                            >{{ t(`ui.risk.levels.${level}`) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-1.5">
                <Label
                    for="filter-kind"
                    class="text-xs text-muted-foreground"
                    >{{ t('scampause.fields.kind') }}</Label
                >
                <Select v-model="kindModel">
                    <SelectTrigger id="filter-kind" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{
                            t('scampause.common.any')
                        }}</SelectItem>
                        <SelectItem
                            v-for="kind in KINDS"
                            :key="kind"
                            :value="kind"
                            >{{
                                enumLabel('payment_request_kind', kind)
                            }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-1.5">
                <Label
                    for="filter-vendor"
                    class="text-xs text-muted-foreground"
                    >{{ t('scampause.fields.vendor') }}</Label
                >
                <Select v-model="vendorModel">
                    <SelectTrigger id="filter-vendor" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{
                            t('scampause.common.any')
                        }}</SelectItem>
                        <SelectItem
                            v-for="vendor in vendors"
                            :key="vendor.id"
                            :value="String(vendor.id)"
                            >{{ vendor.name }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div class="grid gap-1.5">
                    <Label
                        for="filter-from"
                        class="text-xs text-muted-foreground"
                        >{{ t('scampause.common.from') }}</Label
                    >
                    <Input
                        id="filter-from"
                        type="date"
                        :model-value="(filters.from as string | null) ?? ''"
                        :max="(filters.to as string | null) ?? undefined"
                        @update:model-value="
                            (value) => (filters.from = String(value) || null)
                        "
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label
                        for="filter-to"
                        class="text-xs text-muted-foreground"
                        >{{ t('scampause.common.to') }}</Label
                    >
                    <Input
                        id="filter-to"
                        type="date"
                        :model-value="(filters.to as string | null) ?? ''"
                        :min="(filters.from as string | null) ?? undefined"
                        @update:model-value="
                            (value) => (filters.to = String(value) || null)
                        "
                    />
                </div>
            </div>
            <div class="flex items-center gap-3 lg:pb-2">
                <Label
                    for="filter-mine"
                    class="flex cursor-pointer items-center gap-2 text-sm"
                >
                    <Switch id="filter-mine" v-model="mineModel" />
                    {{ t('scampause.requests.mine') }}
                </Label>
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-muted-foreground" aria-live="polite">
                {{ tc('scampause.requests.total', total) }}
            </p>
            <div class="flex items-center gap-2">
                <Button
                    v-if="active()"
                    variant="ghost"
                    size="sm"
                    data-test="reset-filters"
                    @click="reset"
                >
                    <FilterX aria-hidden="true" />
                    {{ t('scampause.common.reset_filters') }}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="hidden lg:inline-flex"
                    :aria-pressed="dense"
                    @click="dense = !dense"
                >
                    <component :is="dense ? Rows3 : Rows4" aria-hidden="true" />
                    {{
                        dense
                            ? t('scampause.common.comfortable')
                            : t('scampause.common.dense')
                    }}
                </Button>
            </div>
        </div>

        <div
            v-if="firstRun"
            class="rounded-xl border bg-card"
            data-test="requests-empty"
        >
            <EmptyState
                :icon="ScanSearch"
                :title="t('scampause.requests.first_title')"
                :description="t('scampause.requests.first_body')"
            >
                <Button v-if="can('payment_requests.create')" as-child>
                    <Link :href="sp.requests.create()">
                        <ScanSearch aria-hidden="true" />
                        {{ t('scampause.dashboard.first_check') }}
                    </Link>
                </Button>
            </EmptyState>
        </div>
        <div
            v-else-if="!requests.data.length"
            class="rounded-xl border bg-card"
            data-test="requests-no-match"
        >
            <EmptyState
                :icon="SearchX"
                tone="muted"
                :title="t('scampause.requests.no_match_title')"
                :description="t('scampause.requests.no_match_body')"
            >
                <Button variant="outline" @click="reset">
                    <FilterX aria-hidden="true" />
                    {{ t('scampause.common.reset_filters') }}
                </Button>
            </EmptyState>
        </div>
        <template v-else>
            <RequestsTable
                :rows="requests.data"
                :caption="t('scampause.requests.title')"
                :dense="dense"
            />
            <LaravelPagination
                :links="requests.links"
                :meta="requests.meta"
                :count="requests.data.length"
                :only="['requests', 'filters', 'counts']"
            />
        </template>
    </div>
</template>
