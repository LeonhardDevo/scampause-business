<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    Building2,
    FilterX,
    PhoneOff,
    Plus,
    SearchX,
    UsersRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import MaskedAccount from '@/components/domain/MaskedAccount.vue';
import StatTile from '@/components/domain/StatTile.vue';
import { Button } from '@/components/ui/button';
import { useRowKeys } from '@/composables/domain/useRowKeys';
import { useCan } from '@/composables/useCan';
import { useFilters } from '@/composables/useFilters';
import { useFormat } from '@/composables/useFormat';
import { sp } from '@/lib/domain/routes';
import { enumLabel } from '@/lib/domain/status';
import type { QueryValue } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import type {
    VendorFilters,
    VendorIndexProps,
    VendorRow,
} from '@/types/domain';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'scampause.nav.vendors', href: sp.vendors.index() },
        ],
    },
});

const props = defineProps<VendorIndexProps>();

const { can } = useCan();
const fmt = useFormat();

const initial = computed<VendorFilters>(() =>
    Array.isArray(props.filters) ? {} : props.filters,
);

const start: Record<string, QueryValue> = {
    q: initial.value.q ?? null,
    kind: initial.value.kind ?? null,
    status: initial.value.status ?? null,
    missing_callback:
        initial.value.missing_callback === true ||
        initial.value.missing_callback === '1' ||
        initial.value.missing_callback === 'true'
            ? 1
            : null,
};

const { filters, reset, active } = useFilters(() => sp.vendors.index(), start, [
    'vendors',
    'filters',
    'stats',
]);

type Segment = 'all' | 'suppliers' | 'employees';

const segment = computed<Segment>({
    get: () =>
        filters.kind === 'employee'
            ? 'employees'
            : filters.kind === 'supplier'
              ? 'suppliers'
              : 'all',
    set: (value) => {
        filters.kind =
            value === 'employees'
                ? 'employee'
                : value === 'suppliers'
                  ? 'supplier'
                  : null;
    },
});

const segments = computed(() => [
    { value: 'all' as const, label: t('scampause.vendors.segments.all') },
    {
        value: 'suppliers' as const,
        label: t('scampause.vendors.segments.suppliers'),
    },
    {
        value: 'employees' as const,
        label: t('scampause.vendors.segments.employees'),
    },
]);

function toggleMissing(): void {
    filters.missing_callback = filters.missing_callback ? null : 1;
}

const table = ref<HTMLElement | null>(null);
useRowKeys(table);

function open(row: VendorRow): void {
    router.visit(sp.vendors.show(row.id).url);
}

const firstRun = computed(() => props.stats.total === 0);
</script>

<template>
    <Head :title="t('scampause.vendors.title')" />

    <div
        class="mx-auto flex w-full max-w-[90rem] flex-col gap-5 px-4 py-6 sm:px-6 lg:py-8"
        data-test="vendors-page"
    >
        <PageHeader
            :icon="Building2"
            :eyebrow="t('scampause.vendors.eyebrow')"
            :title="t('scampause.vendors.title')"
            :description="t('scampause.vendors.description')"
        >
            <template #actions>
                <Button v-if="can('vendors.manage')" as-child>
                    <Link :href="sp.vendors.create()" data-test="add-vendor">
                        <Plus aria-hidden="true" />
                        {{ t('scampause.vendors.add') }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatTile
                :label="t('scampause.vendors.stats.total')"
                :value="fmt.number(stats.total)"
                :icon="Building2"
            />
            <button
                type="button"
                class="text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                :aria-pressed="Boolean(filters.missing_callback)"
                data-test="missing-callback-filter"
                @click="toggleMissing"
            >
                <StatTile
                    :label="t('scampause.vendors.stats.missing_callback')"
                    :value="fmt.number(stats.missing_callback)"
                    :icon="PhoneOff"
                    :tone="stats.missing_callback ? 'warning' : 'default'"
                    :hint="
                        filters.missing_callback
                            ? t('scampause.vendors.filter_on')
                            : t('scampause.vendors.filter_hint')
                    "
                />
            </button>
            <StatTile
                :label="t('scampause.vendors.stats.recent_changes')"
                :value="fmt.number(stats.recent_changes)"
                :icon="ArrowRightLeft"
            />
            <StatTile
                :label="t('scampause.vendors.stats.employees')"
                :value="fmt.number(stats.employees)"
                :icon="UsersRound"
            />
        </div>

        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <SegmentedControl
                v-model="segment"
                :options="segments"
                :label="t('scampause.fields.kind')"
            />
            <div class="flex items-center gap-2">
                <SearchInput
                    :model-value="filters.q as string | null"
                    :placeholder="t('scampause.vendors.search')"
                    :label="t('scampause.common.search')"
                    @update:model-value="(value) => (filters.q = value || null)"
                />
                <Button
                    v-if="active()"
                    variant="ghost"
                    size="sm"
                    @click="reset"
                >
                    <FilterX aria-hidden="true" />
                    {{ t('scampause.common.reset_filters') }}
                </Button>
            </div>
        </div>

        <div
            v-if="firstRun"
            class="rounded-xl border bg-card"
            data-test="vendors-empty"
        >
            <EmptyState
                :icon="Building2"
                :title="t('scampause.vendors.empty_title')"
                :description="t('scampause.vendors.empty_body')"
            >
                <Button v-if="can('vendors.manage')" as-child>
                    <Link :href="sp.vendors.create()">
                        <Plus aria-hidden="true" />
                        {{ t('scampause.vendors.add') }}
                    </Link>
                </Button>
            </EmptyState>
        </div>
        <div v-else-if="!vendors.data.length" class="rounded-xl border bg-card">
            <EmptyState
                :icon="SearchX"
                tone="muted"
                :title="t('scampause.vendors.no_match')"
            >
                <Button variant="outline" @click="reset">
                    <FilterX aria-hidden="true" />
                    {{ t('scampause.common.reset_filters') }}
                </Button>
            </EmptyState>
        </div>
        <template v-else>
            <div ref="table">
                <div
                    class="hidden overflow-x-auto rounded-xl border bg-card md:block"
                >
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                t('scampause.vendors.title')
                            }}
                            ·
                            {{
                                t('scampause.table.keyboard_hint')
                            }}
                        </caption>
                        <thead
                            class="border-b text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th scope="col" class="px-3 py-2.5 font-medium">
                                    {{ t('scampause.vendors.columns.name') }}
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-3 py-2.5 font-medium xl:table-cell"
                                >
                                    {{ t('scampause.vendors.columns.domains') }}
                                </th>
                                <th scope="col" class="px-3 py-2.5 font-medium">
                                    {{
                                        t('scampause.vendors.columns.callback')
                                    }}
                                </th>
                                <th scope="col" class="px-3 py-2.5 font-medium">
                                    {{ t('scampause.vendors.columns.account') }}
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-2.5 text-right font-medium"
                                >
                                    {{ t('scampause.vendors.columns.open') }}
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-3 py-2.5 font-medium xl:table-cell"
                                >
                                    {{
                                        t('scampause.vendors.columns.last_paid')
                                    }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="vendor in vendors.data"
                                :key="vendor.id"
                                tabindex="0"
                                data-row-nav
                                data-test="vendor-row"
                                class="h-11 cursor-pointer outline-none hover:bg-muted/60 focus-visible:bg-accent focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                @click="open(vendor)"
                                @keydown.enter="open(vendor)"
                            >
                                <td class="px-3 py-2">
                                    <p
                                        class="flex items-center gap-2 font-medium"
                                    >
                                        {{ vendor.name }}
                                        <span
                                            v-if="vendor.recent_change"
                                            class="inline-flex h-5 items-center gap-1 rounded-sm border border-warning/30 bg-warning-soft px-1.5 text-[0.6875rem] text-warning-soft-foreground"
                                            ><ArrowRightLeft
                                                class="size-3"
                                                aria-hidden="true"
                                            />{{
                                                t(
                                                    'scampause.vendors.recent_change',
                                                )
                                            }}</span
                                        >
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            enumLabel(
                                                'vendor_kind',
                                                vendor.kind,
                                            )
                                        }}
                                        <template
                                            v-if="vendor.status !== 'active'"
                                        >
                                            ·
                                            {{
                                                enumLabel(
                                                    'vendor_status',
                                                    vendor.status,
                                                )
                                            }}</template
                                        >
                                    </p>
                                </td>
                                <td class="hidden px-3 py-2 xl:table-cell">
                                    <span class="flex flex-wrap gap-1">
                                        <span
                                            v-for="domain in vendor.domains.slice(
                                                0,
                                                2,
                                            )"
                                            :key="domain"
                                            class="rounded-sm border bg-muted px-1.5 font-mono text-[0.6875rem]"
                                            >{{ domain }}</span
                                        >
                                        <span
                                            v-if="vendor.domains.length > 2"
                                            class="text-xs text-muted-foreground"
                                            >+{{
                                                vendor.domains.length - 2
                                            }}</span
                                        >
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <span
                                        v-if="vendor.callback_contact"
                                        class="text-sm"
                                    >
                                        {{ vendor.callback_contact.name }}
                                        <span
                                            class="font-mono text-xs text-muted-foreground"
                                        >
                                            ••••
                                            {{
                                                vendor.callback_contact
                                                    .phone_last4
                                            }}</span
                                        >
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex h-6 items-center gap-1 rounded-sm border border-warning/40 bg-warning-soft px-2 text-xs font-medium text-warning-soft-foreground"
                                        data-test="missing-callback"
                                        ><PhoneOff
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />{{
                                            t('scampause.vendors.no_callback')
                                        }}</span
                                    >
                                </td>
                                <td class="px-3 py-2">
                                    <MaskedAccount
                                        v-if="vendor.verified_account"
                                        :masked="vendor.verified_account.masked"
                                        :last4="vendor.verified_account.last4"
                                    />
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            t(
                                                'scampause.vendors.no_verified_account',
                                            )
                                        }}</span
                                    >
                                </td>
                                <td
                                    class="px-3 py-2 text-right font-mono tabular-nums"
                                >
                                    {{ vendor.open_requests }}
                                </td>
                                <td
                                    class="hidden px-3 py-2 text-muted-foreground xl:table-cell"
                                >
                                    {{
                                        vendor.last_paid_at
                                            ? fmt.date(vendor.last_paid_at)
                                            : '—'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="space-y-2 md:hidden">
                    <li v-for="vendor in vendors.data" :key="vendor.id">
                        <Link
                            :href="sp.vendors.show(vendor.id)"
                            class="block rounded-xl border bg-card p-3 hover:bg-accent/40 focus-visible:outline-2 focus-visible:outline-ring"
                            data-row-nav
                        >
                            <span
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="truncate font-medium">{{
                                    vendor.name
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    enumLabel('vendor_kind', vendor.kind)
                                }}</span>
                            </span>
                            <span
                                class="mt-2 flex flex-wrap items-center gap-2 text-sm"
                            >
                                <span
                                    v-if="!vendor.callback_contact"
                                    class="inline-flex h-6 items-center gap-1 rounded-sm border border-warning/40 bg-warning-soft px-2 text-xs text-warning-soft-foreground"
                                    >{{
                                        t('scampause.vendors.no_callback')
                                    }}</span
                                >
                                <MaskedAccount
                                    v-if="vendor.verified_account"
                                    :masked="vendor.verified_account.masked"
                                />
                            </span>
                        </Link>
                    </li>
                </ul>
            </div>
            <LaravelPagination
                :links="vendors.links"
                :meta="vendors.meta"
                :count="vendors.data.length"
                :only="['vendors', 'filters', 'stats']"
            />
        </template>
    </div>
</template>
