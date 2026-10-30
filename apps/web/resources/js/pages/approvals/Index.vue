<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CheckCheck,
    FolderOpen,
    Globe,
    OctagonX,
    PhoneCall,
    Stamp,
} from '@lucide/vue';
import { siTelegram } from 'simple-icons';
import { computed, nextTick, ref } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import DeadlineCountdown from '@/components/domain/DeadlineCountdown.vue';
import DecisionDialog from '@/components/domain/DecisionDialog.vue';
import type { DecisionSummary } from '@/components/domain/DecisionDialog.vue';
import RiskBadge from '@/components/domain/RiskBadge.vue';
import StatusChip from '@/components/domain/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { deadlineTone } from '@/lib/domain/deadline';
import { requestPath, sp } from '@/lib/domain/routes';
import { enumLabel } from '@/lib/domain/status';
import { t, tc } from '@/lib/i18n';
import type {
    ApprovalItem,
    ApprovalsIndexProps,
    DecisionReason,
    Option,
} from '@/types/domain';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'scampause.nav.approvals', href: sp.approvals.index() },
        ],
    },
});

const props = defineProps<ApprovalsIndexProps>();

const fmt = useFormat();

const DECISION_REASONS: DecisionReason[] = [
    'fraud_confirmed',
    'details_mismatch',
    'not_ordered',
    'duplicate',
    'policy_violation',
    'other',
];

const reasons = computed<Option<DecisionReason>[]>(() =>
    DECISION_REASONS.map((value) => ({
        value,
        label: enumLabel('decision_reason', value),
    })),
);

const items = computed(() =>
    props.tab === 'pending'
        ? [...props.items.data].sort(
              (a, b) =>
                  new Date(a.route_expires_at).getTime() -
                  new Date(b.route_expires_at).getTime(),
          )
        : props.items.data,
);

function switchTab(value: 'pending' | 'decided'): void {
    if (value !== props.tab) {
        router.visit(
            sp.approvals.index(value === 'decided' ? { tab: 'decided' } : {}),
            {
                preserveScroll: true,
            },
        );
    }
}

const open = ref(false);
const mode = ref<'approve' | 'reject'>('approve');
const current = ref<ApprovalItem | null>(null);
const list = ref<HTMLElement | null>(null);

function decide(item: ApprovalItem, next: 'approve' | 'reject'): void {
    current.value = item;
    mode.value = next;
    open.value = true;
}

const summary = computed<DecisionSummary | null>(() => {
    const item = current.value;

    if (!item) {
        return null;
    }

    return {
        reference: item.reference,
        title: item.title,
        vendor: item.vendor?.name ?? null,
        amount: item.amount,
        payee: null,
        risk: item.risk
            ? { score: item.risk.score, level: item.risk.level }
            : null,
        signals: [],
        verification: item.verification_required
            ? t('scampause.approvals.verification_recorded')
            : t('scampause.verification.summary_not_required'),
        policy: null,
    };
});

function inlineAllowed(item: ApprovalItem): boolean {
    if (typeof item.inline_approve === 'boolean') {
        return item.inline_approve;
    }

    return item.risk?.level === 'low' || item.risk?.level === 'medium';
}

async function afterDecision(): Promise<void> {
    await nextTick();
    list.value?.querySelector<HTMLElement>('[data-approval-card]')?.focus();
}

function edgeClass(item: ApprovalItem): string {
    const tone = deadlineTone(item.route_expires_at);

    if (props.tab !== 'pending') {
        return '';
    }

    if (tone === 'critical' || tone === 'expired') {
        return 'border-l-4 border-l-destructive';
    }

    return tone === 'warning' ? 'border-l-4 border-l-warning' : '';
}
</script>

<template>
    <Head :title="t('scampause.approvals.title')" />

    <div
        class="mx-auto flex w-full max-w-5xl flex-col gap-5 px-4 py-6 sm:px-6 lg:py-8"
        data-test="approvals-page"
    >
        <PageHeader
            :icon="Stamp"
            :eyebrow="t('scampause.approvals.eyebrow')"
            :title="t('scampause.approvals.title')"
            :description="t('scampause.approvals.description')"
        />

        <div
            class="flex flex-wrap items-center justify-between gap-3"
            role="tablist"
            :aria-label="t('scampause.approvals.title')"
        >
            <div class="inline-flex rounded-lg border bg-card p-1">
                <button
                    v-for="value in ['pending', 'decided'] as const"
                    :key="value"
                    type="button"
                    role="tab"
                    :aria-selected="tab === value"
                    :class="[
                        'inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring pointer-coarse:min-h-11',
                        tab === value
                            ? 'bg-accent text-accent-foreground'
                            : 'text-muted-foreground hover:bg-muted',
                    ]"
                    :data-test="`approvals-tab-${value}`"
                    @click="switchTab(value)"
                >
                    {{ t(`scampause.approvals.tabs.${value}`) }}
                    <span
                        v-if="value === 'pending'"
                        class="rounded-sm bg-info-soft px-1.5 font-mono text-xs text-info-soft-foreground tabular-nums"
                        >{{ counts.pending }}</span
                    >
                </button>
            </div>
            <p
                v-if="counts.due_soon"
                class="text-sm font-medium text-hazard-ink"
                data-test="due-soon"
            >
                {{ tc('scampause.approvals.due_soon', counts.due_soon) }}
            </p>
        </div>

        <div
            v-if="!items.length"
            class="rounded-xl border bg-card"
            data-test="approvals-empty"
        >
            <EmptyState
                :icon="Stamp"
                :title="
                    tab === 'pending'
                        ? t('scampause.approvals.empty_pending')
                        : t('scampause.approvals.empty_decided')
                "
                :description="
                    tab === 'pending'
                        ? t('scampause.approvals.empty_body')
                        : null
                "
            />
        </div>

        <ul v-else ref="list" class="space-y-3">
            <li v-for="item in items" :key="item.approval.id">
                <article
                    :class="[
                        'rounded-xl border bg-card p-4 shadow-xs outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring sm:p-5',
                        edgeClass(item),
                    ]"
                    tabindex="-1"
                    data-approval-card
                    data-test="approval-card"
                    :data-reference="item.reference"
                >
                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="min-w-0 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    :href="requestPath(item.id)"
                                    class="font-mono text-sm font-semibold hover:underline"
                                    >{{ item.reference }}</Link
                                >
                                <StatusChip :status="item.status" />
                                <RiskBadge
                                    v-if="item.risk"
                                    :level="item.risk.level"
                                    :score="item.risk.score"
                                />
                            </div>
                            <h2 class="truncate font-medium">
                                {{ item.title }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    item.vendor?.name ??
                                    t('scampause.common.unknown_vendor')
                                }}
                                ·
                                {{
                                    enumLabel('payment_request_kind', item.kind)
                                }}
                            </p>
                        </div>
                        <div class="shrink-0 text-left sm:text-right">
                            <p class="text-xl font-semibold tabular-nums">
                                {{ item.amount?.formatted ?? '—' }}
                            </p>
                            <DeadlineCountdown
                                v-if="tab === 'pending'"
                                :at="item.route_expires_at"
                                class="text-sm"
                            />
                            <p
                                v-else-if="item.approval.decided_at"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    enumLabel(
                                        'approval_status',
                                        item.approval.status,
                                    )
                                }}
                                · {{ fmt.dateTime(item.approval.decided_at) }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="mt-4 flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <PhoneCall
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{
                                    item.verification_required
                                        ? t(
                                              'scampause.approvals.verification_recorded',
                                          )
                                        : t(
                                              'scampause.verification.summary_not_required',
                                          )
                                }}
                            </span>
                            <span
                                v-if="tab === 'pending'"
                                class="inline-flex items-center gap-1.5"
                                data-test="channel-availability"
                            >
                                <svg
                                    v-if="item.telegram_allowed"
                                    viewBox="0 0 24 24"
                                    class="size-3.5"
                                    fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path :d="siTelegram.path" />
                                </svg>
                                <Globe
                                    v-else
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{
                                    item.telegram_allowed
                                        ? t(
                                              'scampause.approvals.telegram_available',
                                          )
                                        : t('scampause.approvals.web_only')
                                }}
                            </span>
                            <span v-if="item.approval.reject_reason">
                                {{
                                    enumLabel(
                                        'decision_reason',
                                        item.approval.reject_reason,
                                    )
                                }}
                            </span>
                        </div>
                        <div
                            v-if="item.approval.can_decide"
                            class="flex flex-wrap gap-2 sm:justify-end"
                        >
                            <Button
                                variant="destructive-outline"
                                size="sm"
                                data-test="inline-reject"
                                @click="decide(item, 'reject')"
                            >
                                <OctagonX aria-hidden="true" />
                                {{ t('scampause.actions.reject') }}
                            </Button>
                            <Button
                                v-if="inlineAllowed(item)"
                                size="sm"
                                data-test="inline-approve"
                                @click="decide(item, 'approve')"
                            >
                                <CheckCheck aria-hidden="true" />
                                {{ t('scampause.actions.approve') }}
                            </Button>
                            <Button v-else as-child size="sm">
                                <Link
                                    :href="requestPath(item.id)"
                                    data-test="open-case"
                                >
                                    <FolderOpen aria-hidden="true" />
                                    {{ t('scampause.approvals.open_case') }}
                                </Link>
                            </Button>
                        </div>
                        <Button v-else as-child variant="ghost" size="sm">
                            <Link :href="requestPath(item.id)">
                                <FolderOpen aria-hidden="true" />
                                {{ t('scampause.approvals.open_case') }}
                            </Link>
                        </Button>
                    </div>
                </article>
            </li>
        </ul>

        <LaravelPagination
            v-if="items.length"
            :links="props.items.links"
            :meta="props.items.meta"
            :count="items.length"
        />
    </div>

    <DecisionDialog
        v-if="summary && current"
        v-model:open="open"
        v-model:mode="mode"
        :summary="summary"
        :approve-url="
            inlineAllowed(current)
                ? sp.requests.approve(current.id, current.approval.id).url
                : null
        "
        :reject-url="
            sp.requests.rejectApproval(current.id, current.approval.id).url
        "
        :reasons="reasons"
        @done="afterDecision"
    />
</template>
