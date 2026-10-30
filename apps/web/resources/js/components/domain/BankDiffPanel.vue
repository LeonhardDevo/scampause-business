<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ArrowRightLeft, Eye, Landmark, ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import BankDiffTable from '@/components/domain/BankDiffTable.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useFormat } from '@/composables/useFormat';
import { groupByFour } from '@/lib/domain/bank-validators';
import { enumLabel } from '@/lib/domain/status';
import { describeHttpError } from '@/lib/http-errors';
import { t, tc } from '@/lib/i18n';
import type { BankDiffRow } from '@/types/brand';
import type {
    BankDiff,
    BankDiffOutcome,
    RevealedAccount,
    RevealedBankDetails,
} from '@/types/domain';

const props = withDefaults(
    defineProps<{
        diff: BankDiff;
        canReveal?: boolean;
        revealUrl?: string | null;
        checksumValid?: boolean | null;
    }>(),
    { canReveal: false, revealUrl: null, checksumValid: null },
);

const fmt = useFormat();

const OUTCOME_TONE: Record<BankDiffOutcome, string> = {
    match_verified:
        'bg-success-soft text-success-soft-foreground border-success/30',
    match_pending:
        'bg-warning-soft text-warning-soft-foreground border-warning/30',
    changed: 'bg-risk-critical-tint text-risk-critical border-destructive/30',
    first_use: 'bg-warning-soft text-warning-soft-foreground border-warning/30',
    unmatched_vendor:
        'bg-warning-soft text-warning-soft-foreground border-warning/30',
    no_account_extracted: 'bg-muted text-muted-foreground border-border',
    blocklisted:
        'bg-risk-critical-tint text-risk-critical border-destructive/30',
    other_vendor_account:
        'bg-risk-critical-tint text-risk-critical border-destructive/30',
};

const MONO_FIELDS = new Set(['account', 'routing', 'bic']);

function cellValue(field: string, value: string | null): string {
    if (value === null || value === '') {
        return '—';
    }

    return field === 'scheme' ? enumLabel('bank_scheme', value) : value;
}

function similarityPercent(value: number): number {
    return Math.min(100, Math.max(0, Math.round(value)));
}

const rows = computed<BankDiffRow[]>(() =>
    props.diff.rows.map((row) => ({
        key: row.field,
        label: t(`scampause.bank_diff.fields.${row.field}`),
        onRecord: cellValue(row.field, row.previous),
        inRequest: cellValue(row.field, row.incoming),
        changed: row.changed,
        mono: MONO_FIELDS.has(row.field),
    })),
);

const changedCount = computed(
    () => props.diff.rows.filter((row) => row.changed).length,
);

const http = useHttp<Record<string, never>, RevealedBankDetails>({});
const revealed = ref<RevealedBankDetails | null>(null);
const open = ref(false);

async function reveal(): Promise<void> {
    if (!props.revealUrl) {
        return;
    }

    try {
        const result = await http.post(props.revealUrl);

        if (result) {
            revealed.value = result;
            open.value = true;
        }
    } catch (error) {
        const info = describeHttpError(error);
        toast.error(
            info.status === 429
                ? t('scampause.bank_diff.reveal_throttled')
                : (info.message ?? t('scampause.bank_diff.reveal_failed')),
        );
    }
}

function accountRows(
    account: RevealedAccount | null,
): Array<{ key: string; value: string }> {
    if (!account) {
        return [];
    }

    return [
        { key: 'holder', value: account.holder ?? '—' },
        { key: 'account', value: groupByFour(account.account) },
        { key: 'routing', value: account.routing ?? '—' },
        { key: 'bic', value: account.bic ?? '—' },
    ];
}
</script>

<template>
    <div class="space-y-4" data-test="bank-diff">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <span
                    :class="[
                        'inline-flex h-7 items-center gap-1.5 rounded-sm border px-2.5 text-sm font-medium',
                        OUTCOME_TONE[diff.outcome],
                    ]"
                    data-test="bank-diff-outcome"
                    :data-outcome="diff.outcome"
                >
                    <ArrowRightLeft
                        v-if="diff.outcome === 'changed'"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <Landmark v-else class="size-4" aria-hidden="true" />
                    {{ enumLabel('bank_comparison_outcome', diff.outcome) }}
                </span>
                <span
                    v-if="changedCount"
                    class="text-sm text-muted-foreground"
                    >{{
                        tc('scampause.bank_diff.changed_count', changedCount)
                    }}</span
                >
                <span
                    v-if="checksumValid === false"
                    class="inline-flex h-6 items-center rounded-sm border border-destructive/30 bg-destructive-soft px-2 text-xs font-medium text-destructive"
                    >{{ t('scampause.bank_diff.checksum_invalid') }}</span
                >
            </div>
            <Button
                v-if="canReveal && revealUrl"
                variant="outline"
                size="sm"
                :disabled="http.processing"
                :aria-busy="http.processing"
                data-test="reveal-bank"
                @click="reveal"
            >
                <Eye aria-hidden="true" />
                {{ t('scampause.bank_diff.reveal') }}
            </Button>
        </div>

        <BankDiffTable
            v-if="rows.length"
            :rows="rows"
            :caption="t('scampause.bank_diff.caption')"
        />
        <p v-else class="text-sm text-muted-foreground">
            {{ t('scampause.bank_diff.no_rows') }}
        </p>

        <dl
            v-if="diff.previous"
            class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2"
        >
            <div class="flex gap-2">
                <dt class="text-muted-foreground">
                    {{ t('scampause.bank_diff.verified_at') }}
                </dt>
                <dd>
                    {{
                        diff.previous.verified_at
                            ? fmt.dateTime(diff.previous.verified_at)
                            : t('scampause.bank_diff.not_verified')
                    }}
                </dd>
            </div>
            <div v-if="diff.previous.verified_by" class="flex gap-2">
                <dt class="text-muted-foreground">
                    {{ t('scampause.bank_diff.verified_by') }}
                </dt>
                <dd class="inline-flex items-center gap-1">
                    <ShieldCheck
                        class="size-3.5 text-success"
                        aria-hidden="true"
                    />
                    {{ diff.previous.verified_by.name }}
                </dd>
            </div>
            <div v-if="diff.account_edit_distance !== null" class="flex gap-2">
                <dt class="text-muted-foreground">
                    {{ t('scampause.bank_diff.edit_distance') }}
                </dt>
                <dd class="font-mono tabular-nums">
                    {{ diff.account_edit_distance }}
                </dd>
            </div>
            <div v-if="diff.holder_similarity !== null" class="flex gap-2">
                <dt class="text-muted-foreground">
                    {{ t('scampause.bank_diff.holder_similarity') }}
                </dt>
                <dd class="font-mono tabular-nums">
                    {{ similarityPercent(diff.holder_similarity) }}%
                </dd>
            </div>
        </dl>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-2xl" data-test="reveal-dialog">
                <DialogHeader>
                    <DialogTitle>{{
                        t('scampause.bank_diff.reveal_title')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t('scampause.bank_diff.reveal_logged')
                    }}</DialogDescription>
                </DialogHeader>
                <div v-if="revealed" class="grid gap-4 sm:grid-cols-2">
                    <section
                        v-for="side in ['previous', 'incoming'] as const"
                        :key="side"
                        class="rounded-lg border p-3"
                    >
                        <h3
                            class="eyebrow text-[0.6875rem] text-muted-foreground"
                        >
                            {{
                                side === 'previous'
                                    ? t('ui.bank_diff.on_record')
                                    : t('ui.bank_diff.in_request')
                            }}
                        </h3>
                        <dl
                            v-if="revealed[side]"
                            class="mt-2 space-y-1.5 text-sm"
                        >
                            <div
                                v-for="item in accountRows(revealed[side])"
                                :key="item.key"
                            >
                                <dt class="text-xs text-muted-foreground">
                                    {{
                                        t(
                                            `scampause.bank_diff.fields.${item.key}`,
                                        )
                                    }}
                                </dt>
                                <dd
                                    v-if="
                                        item.key === 'account' &&
                                        side === 'incoming' &&
                                        revealed.char_diff
                                    "
                                    class="font-mono break-all"
                                    data-test="char-diff"
                                >
                                    <span
                                        v-for="(
                                            segment, index
                                        ) in revealed.char_diff"
                                        :key="index"
                                        :class="
                                            segment.changed
                                                ? 'bg-risk-critical-tint text-risk-critical underline decoration-risk-critical decoration-2'
                                                : ''
                                        "
                                        >{{ segment.text }}</span
                                    >
                                </dd>
                                <dd
                                    v-else
                                    :class="
                                        item.key === 'holder'
                                            ? ''
                                            : 'font-mono break-all'
                                    "
                                >
                                    {{ item.value }}
                                </dd>
                            </div>
                        </dl>
                        <p v-else class="mt-2 text-sm text-muted-foreground">
                            {{ t('scampause.bank_diff.nothing_on_record') }}
                        </p>
                    </section>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
