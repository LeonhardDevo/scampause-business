<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AtSign,
    Banknote,
    CheckCheck,
    ChevronDown,
    CircleCheck,
    FileArchive,
    FileText,
    Files,
    Landmark,
    OctagonX,
    PencilLine,
    PhoneCall,
    RefreshCw,
    RotateCcw,
    ScrollText,
    ShieldAlert,
    ShieldCheck,
    Stamp,
    TriangleAlert,
    Undo2,
    UserRoundCheck,
    XCircle,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import BankDiffPanel from '@/components/domain/BankDiffPanel.vue';
import CaseFiles from '@/components/domain/CaseFiles.vue';
import DecisionDialog from '@/components/domain/DecisionDialog.vue';
import type { DecisionSummary } from '@/components/domain/DecisionDialog.vue';
import EvidenceTimeline from '@/components/domain/EvidenceTimeline.vue';
import ExhibitCard from '@/components/domain/ExhibitCard.vue';
import ExtractedFields from '@/components/domain/ExtractedFields.vue';
import MarkPaidDialog from '@/components/domain/MarkPaidDialog.vue';
import MaskedAccount from '@/components/domain/MaskedAccount.vue';
import PipelineStepper from '@/components/domain/PipelineStepper.vue';
import ReasonDialog from '@/components/domain/ReasonDialog.vue';
import ReviewSheet from '@/components/domain/ReviewSheet.vue';
import RiskGauge from '@/components/domain/RiskGauge.vue';
import RouteCard from '@/components/domain/RouteCard.vue';
import SenderPanel from '@/components/domain/SenderPanel.vue';
import SignalList from '@/components/domain/SignalList.vue';
import SourceExcerpt from '@/components/domain/SourceExcerpt.vue';
import StatusChip from '@/components/domain/StatusChip.vue';
import StopCallConfirm from '@/components/domain/StopCallConfirm.vue';
import VerificationSheet from '@/components/domain/VerificationSheet.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { usePipelinePolling } from '@/composables/domain/usePipelinePolling';
import { useFormat } from '@/composables/useFormat';
import { useStableNow } from '@/composables/useStableNow';
import { sp } from '@/lib/domain/routes';
import { pressurePhrases } from '@/lib/domain/signals';
import { enumLabel, isProcessing, roleLabel } from '@/lib/domain/status';
import { t, tc } from '@/lib/i18n';
import type {
    ApprovalDto,
    PaymentRequestShowProps,
    VerificationDto,
} from '@/types/domain';

const props = defineProps<PaymentRequestShowProps>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'scampause.nav.requests', href: sp.requests.index() },
        ],
    },
});

const fmt = useFormat();

const TABS = [
    'overview',
    'bank',
    'sender',
    'verification',
    'approvals',
    'timeline',
    'files',
] as const;
type CaseTab = (typeof TABS)[number];

const tab = ref<CaseTab>('overview');

onMounted(() => {
    const requested = new URLSearchParams(window.location.search).get('tab');

    if (requested && (TABS as readonly string[]).includes(requested)) {
        tab.value = requested as CaseTab;
    }
});

watch(tab, (value) => {
    const url = new URL(window.location.href);

    if (value === 'overview') {
        url.searchParams.delete('tab');
    } else {
        url.searchParams.set('tab', value);
    }

    window.history.replaceState(window.history.state, '', url.toString());
});

const { live, timedOut } = usePipelinePolling(
    () => props.request.id,
    () => props.pipeline,
);

const processing = computed(() => isProcessing(live.value.status));
const level = computed(
    () => props.risk?.level ?? props.request.risk?.level ?? null,
);
const score = computed(
    () => props.risk?.score ?? props.request.risk?.score ?? null,
);
const critical = computed(() => level.value === 'critical');

const myApproval = computed<ApprovalDto | null>(
    () =>
        props.route?.approvals.find((approval) => approval.can_decide) ?? null,
);

const signals = computed(() => props.risk?.signals ?? []);
const phrases = computed(() => pressurePhrases(signals.value));

const satisfying = computed<VerificationDto | null>(
    () =>
        props.verification.attempts.find((attempt) => attempt.satisfies) ??
        null,
);

const now = useStableNow();

const verificationSummary = computed(() => {
    if (satisfying.value) {
        return t('scampause.verification.summary_confirmed', {
            name: satisfying.value.performed_by.name,
            when: fmt.value.relative(satisfying.value.occurred_at, now.value),
        });
    }

    return props.verification.required
        ? t('scampause.verification.summary_missing')
        : t('scampause.verification.summary_not_required');
});

const policyLine = computed(() => {
    const source = props.route ?? props.policy;

    if (!source) {
        return null;
    }

    const roles = source.required_roles
        .map((role) => roleLabel(role))
        .join(', ');

    return roles
        ? tc('scampause.route.policy_line_roles', source.approvals_required, {
              roles,
          })
        : tc('scampause.route.policy_line', source.approvals_required);
});

const summary = computed<DecisionSummary>(() => ({
    reference: props.request.reference,
    title: props.request.title,
    vendor: props.request.vendor?.name ?? null,
    amount: props.request.amount,
    payee: props.request.payee,
    risk:
        score.value !== null && level.value
            ? { score: score.value, level: level.value }
            : null,
    signals: signals.value.slice(0, 3).map((signal) => signal.title),
    verification: verificationSummary.value,
    policy: policyLine.value,
}));

const decisionOpen = ref(false);
const decisionMode = ref<'approve' | 'reject'>('approve');
const decisionApproval = ref<ApprovalDto | null>(null);

function decide(
    approval: ApprovalDto | null,
    mode: 'approve' | 'reject',
): void {
    decisionApproval.value = approval;
    decisionMode.value = mode;
    decisionOpen.value = true;
}

const approveUrl = computed(() =>
    decisionApproval.value
        ? sp.requests.approve(props.request.id, decisionApproval.value.id).url
        : null,
);
const rejectUrl = computed(() =>
    decisionApproval.value
        ? sp.requests.rejectApproval(
              props.request.id,
              decisionApproval.value.id,
          ).url
        : sp.requests.reject(props.request.id).url,
);

const verifyOpen = ref(false);
const reviewOpen = ref(false);
const cancelOpen = ref(false);
const revokeOpen = ref(false);
const paidOpen = ref(false);
const reopenOpen = ref(false);
const submitOpen = ref(false);
const acknowledged = ref(false);
const busy = ref<string | null>(null);

function post(
    action: string,
    url: string,
    data: Record<string, string | number | boolean | null> = {},
): void {
    busy.value = action;
    router.post(url, data, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = null;
        },
    });
}

function submit(): void {
    if (!props.request.vendor) {
        acknowledged.value = false;
        submitOpen.value = true;

        return;
    }

    post('submit', sp.requests.submit(props.request.id).url);
}

function confirmSubmit(): void {
    submitOpen.value = false;
    post('submit', sp.requests.submit(props.request.id).url, {
        unknown_payee_acknowledged: acknowledged.value,
    });
}

function assignVendor(vendorId: number | null): void {
    busy.value = 'vendor';
    router.put(
        sp.requests.vendor(props.request.id).url,
        { vendor_id: vendorId },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

type Primary = 'submit' | 'verify' | 'decide' | 'mark_paid' | 'reopen' | null;

const primary = computed<Primary>(() => {
    if (processing.value) {
        return null;
    }

    if (props.abilities.submit) {
        return 'submit';
    }

    if (props.abilities.verify) {
        return 'verify';
    }

    if (myApproval.value) {
        return 'decide';
    }

    if (props.abilities.mark_paid) {
        return 'mark_paid';
    }

    return props.abilities.reopen ? 'reopen' : null;
});

const hasSecondary = computed(
    () =>
        props.abilities.review ||
        props.abilities.cancel ||
        props.abilities.revoke ||
        props.abilities.export,
);

const revokeReasons = computed(() => props.options.decision_reasons);

const checklistKeys = computed(() => {
    const keys: Array<
        | 'account_last4'
        | 'amount'
        | 'account_holder'
        | 'change_initiated_by_vendor'
        | 'invoice_number'
    > = [];

    if (props.request.payee) {
        keys.push('account_last4', 'account_holder');
    }

    keys.push('amount');

    if (
        props.request.kind === 'bank_details_change' ||
        props.request.kind === 'payroll_change' ||
        props.bank_diff?.outcome === 'changed'
    ) {
        keys.push('change_initiated_by_vendor');
    }

    if (props.request.invoice_number) {
        keys.push('invoice_number');
    }

    return keys;
});

const tabMeta = computed(() => [
    {
        value: 'overview' as const,
        label: t('scampause.case.tabs.overview'),
        icon: ShieldAlert,
        count: signals.value.length || null,
    },
    {
        value: 'bank' as const,
        label: t('scampause.case.tabs.bank'),
        icon: Landmark,
        count: null,
    },
    {
        value: 'sender' as const,
        label: t('scampause.case.tabs.sender'),
        icon: AtSign,
        count: null,
    },
    {
        value: 'verification' as const,
        label: t('scampause.case.tabs.verification'),
        icon: PhoneCall,
        count: props.verification.attempts.length || null,
    },
    {
        value: 'approvals' as const,
        label: t('scampause.case.tabs.approvals'),
        icon: Stamp,
        count: null,
    },
    {
        value: 'timeline' as const,
        label: t('scampause.case.tabs.timeline'),
        icon: ScrollText,
        count: null,
    },
    {
        value: 'files' as const,
        label: t('scampause.case.tabs.files'),
        icon: Files,
        count: props.files.length || null,
    },
]);

const packetBusy = ref(false);

function refreshPacket(): void {
    packetBusy.value = true;
    router.post(
        sp.requests.evidencePacket(props.request.id).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                packetBusy.value = false;
            },
        },
    );
}

const verificationOutcomeTone: Record<string, string> = {
    confirmed: 'text-success',
    denied: 'text-destructive',
    unreachable: 'text-hazard-ink',
    inconclusive: 'text-muted-foreground',
};
</script>

<template>
    <Head :title="`${request.reference} · ${request.title}`" />

    <div
        class="mx-auto w-full max-w-[90rem] px-4 py-6 sm:px-6 lg:py-8"
        data-test="case-file"
        :data-status="live.status"
    >
        <header
            :class="[
                'rounded-xl border bg-card shadow-xs lg:sticky lg:top-16 lg:z-10',
                critical ? 'hazard-edge-l border-destructive/40' : '',
            ]"
            data-test="case-header"
        >
            <div
                class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="flex min-w-0 items-start gap-4">
                    <RiskGauge
                        v-if="score !== null && !processing"
                        :score="score"
                        :level="level"
                        size="sm"
                        class="shrink-0"
                    />
                    <Skeleton
                        v-else-if="processing"
                        class="h-16 w-24 shrink-0 rounded-lg"
                    />
                    <div class="min-w-0 space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="font-mono text-sm font-semibold tracking-[0.02em]"
                                data-test="case-reference"
                                >{{ request.reference }}</span
                            >
                            <StatusChip :status="live.status" />
                            <span class="text-xs text-muted-foreground">{{
                                enumLabel('payment_request_kind', request.kind)
                            }}</span>
                        </div>
                        <h1
                            class="font-display text-xl leading-7 font-semibold text-balance sm:text-2xl"
                        >
                            {{ request.title }}
                        </h1>
                        <p
                            class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
                        >
                            <span
                                class="text-lg font-semibold text-foreground tabular-nums"
                                data-test="case-amount"
                                >{{ request.amount?.formatted ?? '—' }}</span
                            >
                            <span
                                v-if="
                                    request.amount_base &&
                                    request.amount_base.currency !==
                                        request.amount?.currency
                                "
                                class="tabular-nums"
                                >≈ {{ request.amount_base.formatted }}</span
                            >
                            <Link
                                v-if="request.vendor"
                                :href="sp.vendors.show(request.vendor.id)"
                                class="font-medium text-primary hover:underline"
                                >{{ request.vendor.name }}</Link
                            >
                            <span v-else class="text-hazard-ink">{{
                                t('scampause.common.unknown_vendor')
                            }}</span>
                            <MaskedAccount
                                v-if="request.payee"
                                :masked="request.payee.masked"
                                :last4="request.payee.last4"
                            />
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                    <DropdownMenu v-if="hasSecondary && !processing">
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" data-test="case-more">
                                {{ t('scampause.case.more_actions') }}
                                <ChevronDown aria-hidden="true" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-60">
                            <DropdownMenuItem
                                v-if="abilities.review"
                                @select="reviewOpen = true"
                            >
                                <PencilLine aria-hidden="true" />
                                {{ t('scampause.case.edit_fields') }}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="abilities.review"
                                @select="
                                    post(
                                        'reassess',
                                        sp.requests.reassess(request.id).url,
                                    )
                                "
                            >
                                <RefreshCw aria-hidden="true" />
                                {{ t('scampause.case.reassess') }}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="abilities.export"
                                @select="refreshPacket"
                            >
                                <FileArchive aria-hidden="true" />
                                {{ t('scampause.case.evidence_packet') }}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator
                                v-if="
                                    abilities.review ||
                                    abilities.cancel ||
                                    abilities.revoke
                                "
                            />
                            <DropdownMenuItem
                                v-if="abilities.review"
                                class="text-destructive"
                                data-test="case-reject"
                                @select="decide(null, 'reject')"
                            >
                                <OctagonX aria-hidden="true" />
                                {{ t('scampause.case.reject_request') }}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="abilities.revoke"
                                class="text-destructive"
                                @select="revokeOpen = true"
                            >
                                <Undo2 aria-hidden="true" />
                                {{ t('scampause.case.revoke') }}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="abilities.cancel"
                                class="text-destructive"
                                data-test="case-cancel"
                                @select="cancelOpen = true"
                            >
                                <XCircle aria-hidden="true" />
                                {{ t('scampause.case.cancel') }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <Button
                        v-if="primary === 'submit'"
                        :disabled="busy === 'submit'"
                        :aria-busy="busy === 'submit'"
                        data-test="case-submit"
                        @click="submit"
                    >
                        <Spinner v-if="busy === 'submit'" />
                        <Stamp v-else aria-hidden="true" />
                        {{ t('scampause.actions.submit') }}
                    </Button>
                    <Button
                        v-else-if="primary === 'verify'"
                        data-test="case-verify"
                        @click="verifyOpen = true"
                    >
                        <PhoneCall aria-hidden="true" />
                        {{ t('scampause.actions.record_verification') }}
                    </Button>
                    <template v-else-if="primary === 'decide'">
                        <Button
                            variant="destructive-outline"
                            data-test="case-decide-reject"
                            @click="decide(myApproval, 'reject')"
                        >
                            <OctagonX aria-hidden="true" />
                            {{ t('scampause.actions.reject') }}
                        </Button>
                        <Button
                            data-test="case-decide-approve"
                            @click="decide(myApproval, 'approve')"
                        >
                            <CheckCheck aria-hidden="true" />
                            {{ t('scampause.actions.approve') }}
                        </Button>
                    </template>
                    <Button
                        v-else-if="primary === 'mark_paid'"
                        data-test="case-mark-paid"
                        @click="paidOpen = true"
                    >
                        <Banknote aria-hidden="true" />
                        {{ t('scampause.actions.mark_paid') }}
                    </Button>
                    <Button
                        v-else-if="primary === 'reopen'"
                        data-test="case-reopen"
                        @click="reopenOpen = true"
                    >
                        <RotateCcw aria-hidden="true" />
                        {{ t('scampause.actions.reopen') }}
                    </Button>
                </div>
            </div>
        </header>

        <div class="mt-4 space-y-3">
            <div
                v-if="request.blocklisted"
                class="hazard-edge-l flex items-start gap-2 rounded-lg border border-destructive/40 bg-risk-critical-tint py-3 pr-4 pl-5 text-sm text-risk-critical"
                role="alert"
                data-test="blocklisted-banner"
            >
                <ShieldAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                {{ t('scampause.case.blocklisted') }}
            </div>
            <div
                v-if="request.extraction_failed && !processing"
                class="flex flex-col gap-3 rounded-lg border border-warning/40 bg-warning-soft px-4 py-3 text-sm text-warning-soft-foreground sm:flex-row sm:items-center sm:justify-between"
                data-test="extraction-failed"
            >
                <p class="flex items-start gap-2">
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    {{ t('scampause.case.extraction_failed') }}
                </p>
                <Button
                    v-if="abilities.review"
                    variant="outline"
                    size="sm"
                    @click="reviewOpen = true"
                >
                    <PencilLine aria-hidden="true" />
                    {{ t('scampause.case.enter_manually') }}
                </Button>
            </div>
            <div
                v-if="request.prevented"
                class="flex items-start gap-2 rounded-lg border border-success/30 bg-success-soft px-4 py-3 text-sm text-success-soft-foreground"
                data-test="prevented-banner"
            >
                <ShieldCheck
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                {{
                    t('scampause.case.prevented', {
                        category: enumLabel(
                            'prevented_category',
                            request.prevented.category,
                        ),
                        amount: request.prevented.amount?.formatted ?? '—',
                    })
                }}
            </div>
            <div
                v-if="request.employee_response"
                :class="[
                    'flex items-start gap-2 rounded-lg border px-4 py-3 text-sm',
                    request.employee_response === 'not_me'
                        ? 'border-destructive/40 bg-risk-critical-tint text-risk-critical'
                        : 'border-border bg-muted text-foreground',
                ]"
            >
                <UserRoundCheck
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                {{
                    t('scampause.case.employee_response', {
                        response: enumLabel(
                            'employee_response',
                            request.employee_response,
                        ),
                    })
                }}
            </div>
            <div
                v-if="
                    request.decided_at &&
                    (live.status === 'rejected' ||
                        live.status === 'cancelled' ||
                        live.status === 'approved' ||
                        live.status === 'paid')
                "
                class="rounded-lg border bg-card px-4 py-3 text-sm"
                data-test="decision-banner"
            >
                <p class="font-medium">
                    {{
                        t('scampause.case.decided', {
                            status: enumLabel(
                                'payment_request_status',
                                live.status,
                            ),
                            name:
                                request.decided_by?.name ??
                                t('scampause.timeline.system'),
                            when: fmt.dateTime(request.decided_at),
                        })
                    }}
                </p>
                <p v-if="request.decision_reason" class="text-muted-foreground">
                    {{ enumLabel('decision_reason', request.decision_reason) }}
                    <template v-if="request.decision_note"
                        >— {{ request.decision_note }}</template
                    >
                </p>
                <p v-if="request.paid_at" class="text-muted-foreground">
                    {{
                        t('scampause.case.paid_line', {
                            date: fmt.date(request.paid_at),
                            reference: request.payment_reference ?? '—',
                        })
                    }}
                </p>
            </div>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="min-w-0">
                <section
                    v-if="processing"
                    class="space-y-4"
                    data-test="case-processing"
                >
                    <ExhibitCard
                        :eyebrow="t('scampause.pipeline.eyebrow')"
                        :title="t('scampause.pipeline.title')"
                        :description="t('scampause.pipeline.body')"
                    >
                        <PipelineStepper :pipeline="live" />
                        <p
                            v-if="timedOut"
                            class="mt-4 flex flex-wrap items-center gap-3 text-sm text-hazard-ink"
                        >
                            {{ t('scampause.pipeline.timed_out') }}
                            <Button
                                variant="outline"
                                size="sm"
                                @click="router.reload()"
                            >
                                <RefreshCw aria-hidden="true" />
                                {{ t('scampause.common.reload') }}
                            </Button>
                        </p>
                    </ExhibitCard>
                    <div class="grid gap-4 md:grid-cols-2">
                        <Skeleton class="h-56 rounded-xl" />
                        <Skeleton class="h-56 rounded-xl" />
                    </div>
                </section>

                <Tabs v-else v-model="tab" class="gap-4">
                    <div class="-mx-1 overflow-x-auto px-1 pb-1">
                        <TabsList class="inline-flex w-max">
                            <TabsTrigger
                                v-for="item in tabMeta"
                                :key="item.value"
                                :value="item.value"
                                :data-test="`case-tab-${item.value}`"
                            >
                                <component :is="item.icon" aria-hidden="true" />
                                {{ item.label }}
                                <span
                                    v-if="item.count"
                                    class="rounded-sm bg-muted px-1 font-mono text-[0.6875rem] tabular-nums"
                                    >{{ item.count }}</span
                                >
                            </TabsTrigger>
                        </TabsList>
                    </div>

                    <TabsContent value="overview" class="space-y-4">
                        <ExhibitCard
                            :eyebrow="t('scampause.case.exhibits.signals')"
                            :title="t('scampause.case.signals_title')"
                            :tone="critical ? 'critical' : 'default'"
                        >
                            <template v-if="risk" #actions>
                                <span
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{
                                        t('scampause.case.rules_version', {
                                            version: risk.rules_version,
                                        })
                                    }}
                                    · {{ fmt.dateTime(risk.assessed_at) }}
                                </span>
                            </template>
                            <SignalList
                                v-if="risk"
                                :signals="risk.signals"
                                :floor-signal="risk.floor_signal"
                            />
                            <EmptyState
                                v-else
                                :icon="ShieldAlert"
                                tone="muted"
                                compact
                                :title="t('scampause.case.not_assessed')"
                            />
                            <p
                                class="mt-4 text-xs text-pretty text-muted-foreground"
                            >
                                {{ t('ui.risk.disclaimer') }}
                            </p>
                        </ExhibitCard>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <ExhibitCard
                                :eyebrow="t('scampause.case.exhibits.document')"
                                :title="t('scampause.case.extracted_title')"
                            >
                                <template v-if="abilities.review" #actions>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="reviewOpen = true"
                                    >
                                        <PencilLine aria-hidden="true" />
                                        {{ t('scampause.case.edit_fields') }}
                                    </Button>
                                </template>
                                <ExtractedFields :extraction="extraction" />
                            </ExhibitCard>

                            <ExhibitCard
                                :eyebrow="t('scampause.case.exhibits.payee')"
                                :title="t('scampause.case.vendor_title')"
                            >
                                <dl class="space-y-2 text-sm">
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-muted-foreground">
                                            {{ t('scampause.fields.vendor') }}
                                        </dt>
                                        <dd class="text-right font-medium">
                                            {{
                                                request.vendor?.name ??
                                                t(
                                                    'scampause.common.unknown_vendor',
                                                )
                                            }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="request.vendor_match.method"
                                        class="flex justify-between gap-3"
                                    >
                                        <dt class="text-muted-foreground">
                                            {{ t('scampause.case.matched_by') }}
                                        </dt>
                                        <dd class="text-right">
                                            {{
                                                enumLabel(
                                                    'vendor_match_method',
                                                    request.vendor_match.method,
                                                )
                                            }}
                                            <span
                                                v-if="
                                                    request.vendor_match
                                                        .confidence !== null
                                                "
                                                class="font-mono text-xs text-muted-foreground"
                                            >
                                                ·
                                                {{
                                                    request.vendor_match
                                                        .confidence
                                                }}%</span
                                            >
                                        </dd>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-muted-foreground">
                                            {{ t('scampause.fields.payee') }}
                                        </dt>
                                        <dd class="text-right">
                                            <MaskedAccount
                                                :masked="request.payee?.masked"
                                                :last4="request.payee?.last4"
                                            />
                                            <p
                                                v-if="
                                                    request.payee?.holder_masked
                                                "
                                                class="mt-0.5 text-xs text-muted-foreground"
                                            >
                                                {{
                                                    request.payee.holder_masked
                                                }}
                                            </p>
                                        </dd>
                                    </div>
                                    <div
                                        v-if="request.invoice_number"
                                        class="flex justify-between gap-3"
                                    >
                                        <dt class="text-muted-foreground">
                                            {{
                                                t(
                                                    'scampause.fields.invoice_number',
                                                )
                                            }}
                                        </dt>
                                        <dd class="font-mono">
                                            {{ request.invoice_number }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="request.due_date"
                                        class="flex justify-between gap-3"
                                    >
                                        <dt class="text-muted-foreground">
                                            {{ t('scampause.fields.due_date') }}
                                        </dt>
                                        <dd>
                                            {{ fmt.date(request.due_date) }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="request.fx"
                                        class="flex justify-between gap-3"
                                    >
                                        <dt class="text-muted-foreground">
                                            {{ t('scampause.case.fx') }}
                                        </dt>
                                        <dd class="font-mono text-xs">
                                            {{ request.fx.rate }} ·
                                            {{ fmt.date(request.fx.date) }}
                                        </dd>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-muted-foreground">
                                            {{
                                                t(
                                                    'scampause.fields.requested_by',
                                                )
                                            }}
                                        </dt>
                                        <dd>
                                            {{ request.requested_by.name }} ·
                                            {{ fmt.date(request.created_at) }}
                                        </dd>
                                    </div>
                                </dl>
                                <div
                                    v-if="
                                        abilities.review &&
                                        vendor_candidates.length
                                    "
                                    class="mt-4 space-y-2 border-t pt-4"
                                    data-test="vendor-candidates"
                                >
                                    <p class="text-sm font-medium">
                                        {{ t('scampause.case.candidates') }}
                                    </p>
                                    <ul class="space-y-1.5">
                                        <li
                                            v-for="candidate in vendor_candidates"
                                            :key="candidate.id"
                                            class="flex items-center justify-between gap-2 rounded-md border px-3 py-2"
                                        >
                                            <span class="min-w-0">
                                                <span
                                                    class="block truncate font-medium"
                                                    >{{ candidate.name }}</span
                                                >
                                                <span
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {{
                                                        enumLabel(
                                                            'vendor_match_method',
                                                            candidate.method,
                                                        )
                                                    }}
                                                    ·
                                                    {{ candidate.confidence }}%
                                                </span>
                                            </span>
                                            <Button
                                                v-if="
                                                    request.vendor?.id !==
                                                    candidate.id
                                                "
                                                variant="outline"
                                                size="sm"
                                                :disabled="busy === 'vendor'"
                                                @click="
                                                    assignVendor(candidate.id)
                                                "
                                            >
                                                {{ t('scampause.case.assign') }}
                                            </Button>
                                            <CircleCheck
                                                v-else
                                                class="size-4 text-success"
                                                :aria-label="
                                                    t('scampause.case.assigned')
                                                "
                                            />
                                        </li>
                                    </ul>
                                    <Button
                                        v-if="request.vendor"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="busy === 'vendor'"
                                        @click="assignVendor(null)"
                                    >
                                        {{ t('scampause.case.unassign') }}
                                    </Button>
                                </div>
                            </ExhibitCard>
                        </div>

                        <ExhibitCard
                            v-if="source_excerpt"
                            :eyebrow="t('scampause.case.exhibits.message')"
                            :title="t('scampause.case.pressure_title')"
                            :description="
                                phrases.length
                                    ? t('scampause.case.pressure_body')
                                    : null
                            "
                        >
                            <SourceExcerpt
                                :html="source_excerpt"
                                :phrases="phrases"
                            />
                        </ExhibitCard>
                    </TabsContent>

                    <TabsContent value="bank">
                        <ExhibitCard
                            :eyebrow="t('scampause.case.exhibits.bank')"
                            :title="t('scampause.case.bank_title')"
                            :tone="
                                bank_diff?.outcome === 'changed' ||
                                bank_diff?.outcome === 'blocklisted'
                                    ? 'critical'
                                    : 'default'
                            "
                        >
                            <BankDiffPanel
                                v-if="bank_diff"
                                :diff="bank_diff"
                                :can-reveal="abilities.reveal"
                                :reveal-url="sp.requests.reveal(request.id).url"
                                :checksum-valid="request.payee_checksum_valid"
                            />
                            <EmptyState
                                v-else
                                :icon="Landmark"
                                tone="muted"
                                compact
                                :title="t('scampause.bank_diff.empty')"
                            />
                        </ExhibitCard>
                    </TabsContent>

                    <TabsContent value="sender">
                        <ExhibitCard
                            :eyebrow="t('scampause.case.exhibits.sender')"
                            :title="t('scampause.case.sender_title')"
                        >
                            <SenderPanel v-if="sender" :sender="sender" />
                            <EmptyState
                                v-else
                                :icon="AtSign"
                                tone="muted"
                                compact
                                :title="t('scampause.sender.empty_title')"
                                :description="t('scampause.sender.empty_body')"
                            />
                        </ExhibitCard>
                    </TabsContent>

                    <TabsContent value="verification" class="space-y-4">
                        <ExhibitCard
                            :eyebrow="t('scampause.case.exhibits.callback')"
                            :title="t('scampause.verification.title')"
                            :tone="
                                verification.required && !verification.satisfied
                                    ? 'warning'
                                    : 'default'
                            "
                        >
                            <template v-if="abilities.verify" #actions>
                                <Button
                                    size="sm"
                                    data-test="open-verification"
                                    @click="verifyOpen = true"
                                >
                                    <PhoneCall aria-hidden="true" />
                                    {{
                                        t(
                                            'scampause.actions.record_verification',
                                        )
                                    }}
                                </Button>
                            </template>
                            <div class="space-y-4">
                                <p
                                    :class="[
                                        'flex items-start gap-2 text-sm font-medium',
                                        verification.satisfied
                                            ? 'text-success'
                                            : verification.required
                                              ? 'text-hazard-ink'
                                              : 'text-muted-foreground',
                                    ]"
                                    data-test="verification-state"
                                >
                                    <ShieldCheck
                                        v-if="verification.satisfied"
                                        class="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <PhoneCall
                                        v-else
                                        class="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {{ verificationSummary }}
                                </p>
                                <ul
                                    v-if="verification.reasons.length"
                                    class="flex flex-wrap gap-1.5"
                                >
                                    <li
                                        v-for="reason in verification.reasons"
                                        :key="reason"
                                        class="inline-flex h-6 items-center rounded-sm border bg-muted px-2 text-xs"
                                    >
                                        {{ reason }}
                                    </li>
                                </ul>
                                <div
                                    v-if="verification.attempts.length"
                                    class="space-y-2"
                                >
                                    <h3
                                        class="eyebrow text-[0.6875rem] text-muted-foreground"
                                    >
                                        {{
                                            t('scampause.verification.attempts')
                                        }}
                                    </h3>
                                    <ul class="divide-y rounded-lg border">
                                        <li
                                            v-for="attempt in verification.attempts"
                                            :key="attempt.id"
                                            class="space-y-1 px-3 py-3 text-sm"
                                            data-test="verification-attempt"
                                            :data-outcome="attempt.outcome"
                                        >
                                            <div
                                                class="flex flex-wrap items-baseline justify-between gap-2"
                                            >
                                                <p class="font-medium">
                                                    <span
                                                        :class="
                                                            verificationOutcomeTone[
                                                                attempt.outcome
                                                            ]
                                                        "
                                                    >
                                                        {{
                                                            enumLabel(
                                                                'verification_outcome',
                                                                attempt.outcome,
                                                            )
                                                        }}</span
                                                    >
                                                    ·
                                                    {{
                                                        enumLabel(
                                                            'verification_method',
                                                            attempt.method,
                                                        )
                                                    }}
                                                </p>
                                                <time
                                                    class="text-xs text-muted-foreground"
                                                    :datetime="
                                                        attempt.occurred_at
                                                    "
                                                >
                                                    {{
                                                        fmt.dateTime(
                                                            attempt.occurred_at,
                                                        )
                                                    }}
                                                </time>
                                            </div>
                                            <p class="text-muted-foreground">
                                                {{
                                                    t(
                                                        'scampause.verification.attempt_line',
                                                        {
                                                            performer:
                                                                attempt
                                                                    .performed_by
                                                                    .name,
                                                            spoken: attempt.spoken_to_name,
                                                        },
                                                    )
                                                }}
                                                <template
                                                    v-if="attempt.contact"
                                                >
                                                    ·
                                                    {{ attempt.contact.name }}
                                                    ••••
                                                    {{
                                                        attempt.contact
                                                            .phone_last4
                                                    }}
                                                </template>
                                            </p>
                                            <ul class="flex flex-wrap gap-1.5">
                                                <li
                                                    v-for="(
                                                        checked, key
                                                    ) in attempt.checklist"
                                                    :key="key"
                                                    :class="[
                                                        'inline-flex h-5 items-center gap-1 rounded-sm border px-1.5 text-[0.6875rem]',
                                                        checked
                                                            ? 'border-success/30 bg-success-soft text-success-soft-foreground'
                                                            : 'bg-muted text-muted-foreground line-through',
                                                    ]"
                                                >
                                                    {{
                                                        t(
                                                            `domain.checklist.${key}`,
                                                        )
                                                    }}
                                                </li>
                                            </ul>
                                            <p
                                                v-if="attempt.notes"
                                                class="text-pretty"
                                            >
                                                {{ attempt.notes }}
                                            </p>
                                            <p
                                                v-if="attempt.sod_waived"
                                                class="text-xs text-hazard-ink"
                                            >
                                                {{
                                                    t(
                                                        'scampause.verification.sod_waived',
                                                    )
                                                }}
                                            </p>
                                            <p
                                                v-if="attempt.satisfies"
                                                class="text-xs font-medium text-success"
                                            >
                                                {{
                                                    t(
                                                        'scampause.verification.satisfies',
                                                    )
                                                }}
                                            </p>
                                        </li>
                                    </ul>
                                </div>
                                <p v-else class="text-sm text-muted-foreground">
                                    {{
                                        t('scampause.verification.no_attempts')
                                    }}
                                </p>
                            </div>
                        </ExhibitCard>
                    </TabsContent>

                    <TabsContent value="approvals" class="space-y-4">
                        <ExhibitCard
                            :eyebrow="t('scampause.case.exhibits.approvals')"
                            :title="t('scampause.route.title')"
                        >
                            <RouteCard
                                v-if="route"
                                :route="route"
                                :request-id="request.id"
                                :candidates="options.reassign_candidates"
                                @decide="decide"
                            />
                            <div
                                v-else-if="policy"
                                class="space-y-3 text-sm"
                                data-test="policy-preview"
                            >
                                <p class="font-medium">
                                    {{ t('scampause.route.preview_title') }}
                                </p>
                                <p>{{ policyLine }}</p>
                                <p
                                    v-if="policy.verification_required"
                                    class="text-hazard-ink"
                                >
                                    {{
                                        t(
                                            'scampause.route.preview_verification',
                                        )
                                    }}
                                </p>
                                <p class="text-muted-foreground">
                                    {{
                                        t('scampause.route.preview_expiry', {
                                            hours: policy.expiry_hours,
                                        })
                                    }}
                                    ·
                                    {{
                                        tc(
                                            'scampause.route.eligible',
                                            policy.eligible_approvers,
                                        )
                                    }}
                                </p>
                                <ul
                                    class="list-inside list-disc text-muted-foreground"
                                >
                                    <li
                                        v-for="rule in policy.matched_rules"
                                        :key="`${rule.id}-${rule.version}`"
                                    >
                                        {{ rule.name }}
                                    </li>
                                </ul>
                                <ul
                                    v-if="policy.notes.length"
                                    class="space-y-1 text-hazard-ink"
                                >
                                    <li
                                        v-for="note in policy.notes"
                                        :key="note"
                                    >
                                        {{ note }}
                                    </li>
                                </ul>
                            </div>
                            <EmptyState
                                v-else
                                :icon="Stamp"
                                tone="muted"
                                compact
                                :title="t('scampause.route.none')"
                            />
                        </ExhibitCard>
                        <ExhibitCard
                            v-for="past in past_routes"
                            :key="past.id"
                            :eyebrow="
                                t('scampause.route.past', {
                                    attempt: past.attempt,
                                })
                            "
                        >
                            <RouteCard
                                :route="past"
                                :request-id="request.id"
                                compact
                            />
                        </ExhibitCard>
                    </TabsContent>

                    <TabsContent value="timeline" class="space-y-4">
                        <ExhibitCard
                            v-if="abilities.export || evidence_packet"
                            :eyebrow="t('scampause.case.exhibits.evidence')"
                            :title="t('scampause.packet.title')"
                            data-test="evidence-packet"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-3 text-sm"
                            >
                                <p
                                    v-if="evidence_packet"
                                    class="flex items-center gap-2"
                                >
                                    <FileText
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    {{
                                        enumLabel(
                                            'export_status',
                                            evidence_packet.status,
                                        )
                                    }}
                                    <span
                                        v-if="
                                            evidence_packet.chain_verified !==
                                            null
                                        "
                                        :class="
                                            evidence_packet.chain_verified
                                                ? 'text-success'
                                                : 'text-destructive'
                                        "
                                    >
                                        ·
                                        {{
                                            evidence_packet.chain_verified
                                                ? t('scampause.packet.chain_ok')
                                                : t(
                                                      'scampause.packet.chain_broken',
                                                  )
                                        }}
                                    </span>
                                </p>
                                <p v-else class="text-muted-foreground">
                                    {{ t('scampause.packet.none') }}
                                </p>
                                <div class="flex gap-2">
                                    <Button
                                        v-if="evidence_packet?.download_url"
                                        as="a"
                                        :href="evidence_packet.download_url"
                                        variant="outline"
                                        size="sm"
                                    >
                                        {{ t('scampause.packet.download') }}
                                    </Button>
                                    <Button
                                        v-if="abilities.export"
                                        size="sm"
                                        variant="secondary"
                                        :disabled="packetBusy"
                                        @click="refreshPacket"
                                    >
                                        <Spinner v-if="packetBusy" />
                                        {{
                                            evidence_packet
                                                ? t('scampause.packet.refresh')
                                                : t('scampause.packet.create')
                                        }}
                                    </Button>
                                </div>
                            </div>
                        </ExhibitCard>
                        <ExhibitCard
                            :eyebrow="t('scampause.case.exhibits.timeline')"
                            :title="t('scampause.timeline.title')"
                        >
                            <EvidenceTimeline
                                :items="timeline"
                                :deliveries="deliveries"
                            />
                        </ExhibitCard>
                    </TabsContent>

                    <TabsContent value="files" class="space-y-4">
                        <ExhibitCard
                            :eyebrow="t('scampause.case.exhibits.files')"
                            :title="t('scampause.files.title')"
                        >
                            <CaseFiles
                                :files="files"
                                :previews="preview_pages"
                            />
                        </ExhibitCard>
                        <ExhibitCard
                            v-if="source_excerpt"
                            :eyebrow="t('scampause.case.exhibits.message')"
                            :title="t('scampause.excerpt.title')"
                        >
                            <SourceExcerpt
                                :html="source_excerpt"
                                :phrases="phrases"
                            />
                        </ExhibitCard>
                    </TabsContent>
                </Tabs>
            </div>

            <aside
                class="space-y-4 xl:sticky xl:top-48 xl:self-start"
                :aria-label="t('scampause.case.next_steps')"
            >
                <StopCallConfirm
                    :contacts="verification.callback_contacts"
                    show-contacts
                    :missing-contact="
                        Boolean(request.vendor) &&
                        !verification.vendor_has_callback_contact
                    "
                />
                <section
                    v-if="policyLine"
                    class="rounded-xl border bg-card p-4 text-sm shadow-xs"
                >
                    <p class="eyebrow text-[0.6875rem] text-muted-foreground">
                        {{ t('scampause.route.policy') }}
                    </p>
                    <p class="mt-1 font-medium">{{ policyLine }}</p>
                    <p
                        v-if="verification.required"
                        class="mt-1 text-hazard-ink"
                    >
                        {{ t('scampause.route.preview_verification') }}
                    </p>
                </section>
            </aside>
        </div>
    </div>

    <DecisionDialog
        v-model:open="decisionOpen"
        v-model:mode="decisionMode"
        :summary="summary"
        :approve-url="approveUrl"
        :reject-url="rejectUrl"
        :reasons="options.decision_reasons"
    />
    <VerificationSheet
        v-model:open="verifyOpen"
        :action="sp.requests.verify(request.id).url"
        :methods="options.verification_methods"
        :contacts="verification.callback_contacts"
        :checklist="checklistKeys"
        :require-last4="Boolean(request.payee)"
        :replaceable="verification.replaceable_accounts"
    />
    <ReviewSheet
        v-if="abilities.review"
        v-model:open="reviewOpen"
        :request="request"
        :kinds="options.kinds"
    />
    <ReasonDialog
        v-model:open="cancelOpen"
        :title="t('scampause.case.cancel_title')"
        :description="t('scampause.case.cancel_body')"
        :url="sp.requests.cancel(request.id).url"
        :reasons="options.cancel_reasons"
        :confirm-label="t('scampause.case.cancel')"
        test-id="cancel-dialog"
    />
    <ReasonDialog
        v-model:open="revokeOpen"
        :title="t('scampause.case.revoke_title')"
        :description="t('scampause.case.revoke_body')"
        :url="sp.requests.revoke(request.id).url"
        :reasons="revokeReasons"
        :confirm-label="t('scampause.case.revoke')"
        comment-always-required
        test-id="revoke-dialog"
    />
    <MarkPaidDialog
        v-model:open="paidOpen"
        :url="sp.requests.markPaid(request.id).url"
        :amount="request.amount?.formatted ?? null"
    />
    <ConfirmDialog
        v-model:open="reopenOpen"
        :title="t('scampause.case.reopen_title')"
        :description="t('scampause.case.reopen_body')"
        :confirm-label="t('scampause.actions.reopen')"
        :processing="busy === 'reopen'"
        @confirm="
            post('reopen', sp.requests.reopen(request.id).url);
            reopenOpen = false;
        "
    />
    <Dialog v-model:open="submitOpen">
        <DialogContent class="sm:max-w-md" data-test="submit-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    t('scampause.case.submit_unknown_title')
                }}</DialogTitle>
                <DialogDescription>{{
                    t('scampause.case.submit_unknown_body')
                }}</DialogDescription>
            </DialogHeader>
            <Label
                for="ack-unknown"
                class="flex cursor-pointer items-start gap-3 rounded-md border p-3 font-normal"
            >
                <Checkbox
                    id="ack-unknown"
                    v-model="acknowledged"
                    class="mt-0.5 size-5"
                />
                {{ t('scampause.case.submit_unknown_ack') }}
            </Label>
            <DialogFooter>
                <Button variant="outline" @click="submitOpen = false">{{
                    t('scampause.common.cancel')
                }}</Button>
                <Button
                    :disabled="!acknowledged"
                    data-test="submit-confirm"
                    @click="confirmSubmit"
                >
                    <Stamp aria-hidden="true" />
                    {{ t('scampause.actions.submit') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
