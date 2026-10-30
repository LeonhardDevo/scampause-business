<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    Building2,
    FileSearch,
    Globe,
    PencilLine,
    Plus,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import BankHistoryTimeline from '@/components/domain/BankHistoryTimeline.vue';
import ChartCard from '@/components/domain/ChartCard.vue';
import ExhibitCard from '@/components/domain/ExhibitCard.vue';
import RequestsTable from '@/components/domain/RequestsTable.vue';
import VendorContactsCard from '@/components/domain/VendorContactsCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useScamPause } from '@/composables/domain/useScamPause';
import { useFormat } from '@/composables/useFormat';
import {
    areaFill,
    axisTooltip,
    baseGrid,
    categoryAxis,
    valueAxis,
} from '@/lib/charts';
import { compactMoney, formatMinor } from '@/lib/domain/money';
import { sp } from '@/lib/domain/routes';
import { enumLabel } from '@/lib/domain/status';
import { activeLocale, INTL_LOCALE, t } from '@/lib/i18n';
import type { ChartTheme } from '@/lib/theme';
import type {
    CallbackContact,
    DomainSource,
    VendorShowProps,
} from '@/types/domain';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'scampause.nav.vendors', href: sp.vendors.index() },
        ],
    },
});

const props = defineProps<VendorShowProps>();

const fmt = useFormat();
const shared = useScamPause();
const currency = computed(
    () => props.vendor.default_currency ?? shared.value?.base_currency ?? 'USD',
);
const baseCurrency = computed(() => shared.value?.base_currency ?? 'USD');

const callbackContacts = computed<CallbackContact[]>(() =>
    props.contacts
        .filter(
            (contact) =>
                contact.is_callback &&
                contact.phone_display &&
                (contact.status === undefined || contact.status === 'verified'),
        )
        .map((contact) => ({
            id: contact.id,
            name: contact.name,
            role_title: contact.role_title,
            phone_display: contact.phone_display ?? '',
            source: contact.source,
            source_ref: contact.source_ref,
        })),
);

const DOMAIN_SOURCES: DomainSource[] = [
    'contract',
    'manual',
    'verified_correspondence',
];
const domainForm = useForm({ domain: '', source: 'contract' as DomainSource });

function addDomain(): void {
    if (!domainForm.domain.trim() || domainForm.processing) {
        return;
    }

    domainForm.post(sp.vendors.domains.store(props.vendor.id).url, {
        preserveScroll: true,
        onSuccess: () => domainForm.reset('domain'),
    });
}

function removeDomain(id: number): void {
    router.delete(sp.vendors.domains.destroy(props.vendor.id, id).url, {
        preserveScroll: true,
    });
}

const archiveOpen = ref(false);

function archive(): void {
    router.delete(sp.vendors.destroy(props.vendor.id).url, {
        onFinish: () => {
            archiveOpen.value = false;
        },
    });
}

const historyEmpty = computed(() =>
    props.amount_history.every((point) => point.amount_minor === 0),
);

function monthLabel(month: string): string {
    return new Intl.DateTimeFormat(INTL_LOCALE[activeLocale()], {
        month: 'short',
    }).format(new Date(`${month}-01T00:00:00`));
}

function buildHistory(theme: ChartTheme) {
    return {
        grid: { ...baseGrid(), top: 12 },
        tooltip: {
            ...axisTooltip(theme),
            valueFormatter: (value: number) =>
                formatMinor(
                    Math.round(value),
                    baseCurrency.value,
                    activeLocale(),
                ),
        },
        xAxis: categoryAxis(
            theme,
            props.amount_history.map((point) => monthLabel(point.month)),
        ),
        yAxis: valueAxis(theme, (value: number) =>
            compactMoney(
                value,
                baseCurrency.value,
                INTL_LOCALE[activeLocale()],
            ),
        ),
        series: [
            {
                type: 'line',
                name: t('scampause.vendor.paid'),
                data: props.amount_history.map((point) => point.amount_minor),
                smooth: true,
                symbol: 'circle',
                symbolSize: 5,
                lineStyle: { color: theme.series[0], width: 2 },
                itemStyle: { color: theme.series[0] },
                areaStyle: areaFill(theme.series[0]),
            },
        ],
    };
}

const historyRows = computed(() =>
    props.amount_history.map((point) => [
        point.month,
        formatMinor(point.amount_minor, baseCurrency.value, activeLocale()),
    ]),
);
</script>

<template>
    <Head :title="vendor.name" />

    <div
        class="mx-auto flex w-full max-w-[90rem] flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
        data-test="vendor-page"
    >
        <header
            class="flex flex-col gap-4 border-b pb-5 lg:flex-row lg:items-end lg:justify-between"
        >
            <div class="min-w-0 space-y-2">
                <p
                    class="flex items-center gap-2 eyebrow text-muted-foreground"
                >
                    <span
                        class="inline-block h-3 w-0.5 bg-hazard"
                        aria-hidden="true"
                    />
                    {{ enumLabel('vendor_kind', vendor.kind) }}
                </p>
                <h1
                    class="flex flex-wrap items-center gap-3 font-display text-2xl font-semibold"
                >
                    <Building2
                        class="size-6 text-muted-foreground"
                        :stroke-width="1.5"
                        aria-hidden="true"
                    />
                    {{ vendor.name }}
                    <span
                        :class="[
                            'inline-flex h-6 items-center rounded-sm border px-2 font-sans text-xs font-medium',
                            vendor.status === 'active'
                                ? 'border-success/30 bg-success-soft text-success-soft-foreground'
                                : 'bg-muted text-muted-foreground',
                        ]"
                        >{{ enumLabel('vendor_status', vendor.status) }}</span
                    >
                </h1>
                <dl
                    class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-muted-foreground"
                >
                    <div v-if="vendor.legal_name" class="flex gap-1.5">
                        <dt class="sr-only">
                            {{ t('scampause.vendor_form.legal_name') }}
                        </dt>
                        <dd>{{ vendor.legal_name }}</dd>
                    </div>
                    <div v-if="vendor.tax_id" class="flex gap-1.5">
                        <dt>{{ t('scampause.vendor_form.tax_id') }}</dt>
                        <dd class="font-mono text-foreground">
                            {{ vendor.tax_id }}
                        </dd>
                    </div>
                    <div v-if="vendor.country" class="flex gap-1.5">
                        <dt>{{ t('scampause.vendor_form.country') }}</dt>
                        <dd class="font-mono text-foreground">
                            {{ vendor.country }}
                        </dd>
                    </div>
                    <div
                        v-if="vendor.payment_terms_days !== null"
                        class="flex gap-1.5"
                    >
                        <dt>{{ t('scampause.vendor_form.terms') }}</dt>
                        <dd class="text-foreground">
                            {{ vendor.payment_terms_days }}
                        </dd>
                    </div>
                    <div v-if="vendor.linked_user" class="flex gap-1.5">
                        <dt>{{ t('scampause.vendor_form.linked_user') }}</dt>
                        <dd class="text-foreground">
                            {{ vendor.linked_user.name }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="vendor.aliases.length"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('scampause.vendor_form.aliases') }}:
                    {{ vendor.aliases.join(', ') }}
                </p>
            </div>
            <div v-if="abilities.manage" class="flex flex-wrap gap-2">
                <Button as-child variant="outline">
                    <Link :href="sp.vendors.edit(vendor.id)">
                        <PencilLine aria-hidden="true" />
                        {{ t('scampause.common.edit') }}
                    </Link>
                </Button>
                <Button
                    v-if="vendor.status !== 'archived'"
                    variant="destructive-outline"
                    @click="archiveOpen = true"
                >
                    <Archive aria-hidden="true" />
                    {{ t('scampause.vendor.archive') }}
                </Button>
            </div>
        </header>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div class="min-w-0 space-y-6">
                <BankHistoryTimeline
                    :vendor-id="vendor.id"
                    :accounts="accounts"
                    :blocked-attempts="blocked_attempts"
                    :contacts="callbackContacts"
                    :currencies="[currency]"
                    :abilities="abilities"
                    :default-scheme="
                        vendor.country === 'RU'
                            ? 'ru_account'
                            : vendor.country === 'US'
                              ? 'us_ach'
                              : 'iban'
                    "
                />

                <ExhibitCard
                    :eyebrow="t('scampause.vendor.exhibits.requests')"
                    :title="t('scampause.vendor.requests_title')"
                >
                    <RequestsTable
                        v-if="requests.length"
                        :rows="requests"
                        :caption="t('scampause.vendor.requests_title')"
                        dense
                        :show-requester="false"
                        test-id="vendor-requests"
                    />
                    <EmptyState
                        v-else
                        :icon="FileSearch"
                        tone="muted"
                        compact
                        :title="t('scampause.vendor.no_requests')"
                    />
                </ExhibitCard>
            </div>

            <div class="space-y-6">
                <VendorContactsCard
                    :vendor-id="vendor.id"
                    :contacts="contacts"
                    :can-manage="abilities.manage"
                />

                <ExhibitCard
                    :eyebrow="t('scampause.vendor.exhibits.domains')"
                    :title="t('scampause.vendor.domains_title')"
                    data-test="vendor-domains"
                >
                    <ul v-if="domains.length" class="flex flex-wrap gap-2">
                        <li
                            v-for="domain in domains"
                            :key="domain.id"
                            class="inline-flex h-7 items-center gap-1.5 rounded-sm border bg-muted/60 pr-1 pl-2 font-mono text-xs"
                            :title="enumLabel('domain_source', domain.source)"
                        >
                            <Globe
                                class="size-3.5 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{ domain.domain }}
                            <span
                                v-if="
                                    domain.unicode &&
                                    domain.unicode !== domain.domain
                                "
                                class="font-sans text-muted-foreground"
                                >({{ domain.unicode }})</span
                            >
                            <button
                                v-if="abilities.manage"
                                type="button"
                                class="inline-flex size-5 items-center justify-center rounded-sm hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                                :aria-label="
                                    t('scampause.vendor.remove_domain', {
                                        domain: domain.domain,
                                    })
                                "
                                @click="removeDomain(domain.id)"
                            >
                                <X class="size-3.5" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('scampause.vendor.no_domains') }}
                    </p>
                    <form
                        v-if="abilities.manage"
                        class="mt-4 grid gap-2 sm:grid-cols-[minmax(0,1fr)_10rem_auto] xl:grid-cols-1"
                        @submit.prevent="addDomain"
                    >
                        <label for="domain-input" class="sr-only">{{
                            t('scampause.vendor.domain')
                        }}</label>
                        <Input
                            id="domain-input"
                            v-model="domainForm.domain"
                            class="font-mono"
                            placeholder="example.com"
                            maxlength="253"
                            :aria-invalid="
                                Boolean(domainForm.errors.domain) || undefined
                            "
                        />
                        <Select v-model="domainForm.source">
                            <SelectTrigger
                                class="w-full"
                                :aria-label="
                                    t('scampause.vendor.contact_source')
                                "
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="source in DOMAIN_SOURCES"
                                    :key="source"
                                    :value="source"
                                    >{{
                                        enumLabel('domain_source', source)
                                    }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="
                                !domainForm.domain.trim() ||
                                domainForm.processing
                            "
                        >
                            <Plus aria-hidden="true" />
                            {{ t('scampause.vendor.add_domain') }}
                        </Button>
                        <p
                            v-if="domainForm.errors.domain"
                            class="text-sm text-destructive"
                            role="alert"
                        >
                            {{ domainForm.errors.domain }}
                        </p>
                    </form>
                </ExhibitCard>

                <ChartCard
                    :title="t('scampause.vendor.amount_history')"
                    :description="t('scampause.vendor.amount_history_body')"
                    :summary="
                        t('scampause.vendor.amount_history_summary', {
                            name: vendor.name,
                        })
                    "
                    :build="buildHistory"
                    :columns="[
                        t('scampause.charts.month'),
                        t('scampause.vendor.paid'),
                    ]"
                    :rows="historyRows"
                    :empty="historyEmpty"
                />

                <section
                    v-if="vendor.notes"
                    class="rounded-xl border bg-card p-4 text-sm"
                >
                    <p class="eyebrow text-[0.6875rem] text-muted-foreground">
                        {{ t('scampause.vendor_form.notes') }}
                    </p>
                    <p class="mt-1 text-pretty whitespace-pre-line">
                        {{ vendor.notes }}
                    </p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        {{
                            t('scampause.vendor.created', {
                                date: fmt.date(vendor.created_at),
                            })
                        }}
                    </p>
                </section>
            </div>
        </div>
    </div>

    <ConfirmDialog
        v-model:open="archiveOpen"
        :title="t('scampause.vendor.archive_title')"
        :description="t('scampause.vendor.archive_body')"
        :confirm-label="t('scampause.vendor.archive')"
        destructive
        @confirm="archive"
    />
</template>
