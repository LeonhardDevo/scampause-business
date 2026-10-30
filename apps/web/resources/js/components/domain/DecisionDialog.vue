<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CheckCheck, OctagonX } from '@lucide/vue';
import { computed, watch } from 'vue';
import Kbd from '@/components/common/Kbd.vue';
import FormField from '@/components/domain/FormField.vue';
import MaskedAccount from '@/components/domain/MaskedAccount.vue';
import RiskBadge from '@/components/domain/RiskBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import type {
    DecisionReason,
    MaskedAccount as MaskedAccountDto,
    MoneyDto,
    Option,
    RiskLevel,
} from '@/types/domain';

export type DecisionSummary = {
    reference: string;
    title: string;
    vendor: string | null;
    amount: MoneyDto | null;
    payee: MaskedAccountDto | null;
    risk: { score: number; level: RiskLevel } | null;
    signals: string[];
    verification: string | null;
    policy: string | null;
};

const props = withDefaults(
    defineProps<{
        summary: DecisionSummary;
        approveUrl?: string | null;
        rejectUrl: string;
        reasons: Option<DecisionReason>[];
    }>(),
    { approveUrl: null },
);

const open = defineModel<boolean>('open', { default: false });
const mode = defineModel<'approve' | 'reject'>('mode', { required: true });
const emit = defineEmits<{ done: [mode: 'approve' | 'reject'] }>();

const form = useForm<{ comment: string; reason: DecisionReason | '' }>({
    comment: '',
    reason: '',
});

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
    }
});

const canApprove = computed(() => Boolean(props.approveUrl));

const commentRequired = computed(
    () => mode.value === 'reject' && form.reason === 'other',
);

const invalid = computed(
    () =>
        mode.value === 'reject' &&
        (!form.reason || (commentRequired.value && !form.comment.trim())),
);

function submit(): void {
    if (form.processing || invalid.value) {
        return;
    }

    const url = mode.value === 'approve' ? props.approveUrl : props.rejectUrl;

    if (!url) {
        return;
    }

    const current = mode.value;

    form.transform((data) =>
        current === 'approve'
            ? { comment: data.comment || null }
            : { reason: data.reason, comment: data.comment || null },
    ).post(url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('done', current);
        },
    });
}

function onKeydown(event: KeyboardEvent): void {
    const target = event.target;

    if (
        target instanceof HTMLTextAreaElement ||
        target instanceof HTMLInputElement
    ) {
        return;
    }

    if (event.metaKey || event.ctrlKey || event.altKey) {
        return;
    }

    if (event.key === 'a' && canApprove.value) {
        event.preventDefault();

        if (mode.value === 'approve') {
            submit();
        } else {
            mode.value = 'approve';
        }
    } else if (event.key === 'r') {
        event.preventDefault();
        mode.value = 'reject';
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-xl"
            data-test="decision-dialog"
            :data-mode="mode"
            @keydown="onKeydown"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        mode === 'approve'
                            ? t('scampause.decision.approve_title')
                            : t('scampause.decision.reject_title')
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        mode === 'approve'
                            ? t('scampause.decision.approve_body')
                            : t('scampause.decision.reject_body')
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-3 rounded-lg border bg-muted/30 p-3 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="min-w-0">
                        <span class="font-mono font-medium">{{
                            summary.reference
                        }}</span>
                        · {{ summary.title }}
                    </p>
                    <RiskBadge
                        v-if="summary.risk"
                        :level="summary.risk.level"
                        :score="summary.risk.score"
                    />
                </div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                    <dt class="text-muted-foreground">
                        {{ t('scampause.fields.vendor') }}
                    </dt>
                    <dd>
                        {{
                            summary.vendor ??
                            t('scampause.common.unknown_vendor')
                        }}
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('scampause.fields.amount') }}
                    </dt>
                    <dd class="font-semibold tabular-nums">
                        {{ summary.amount?.formatted ?? '—' }}
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('scampause.fields.payee') }}
                    </dt>
                    <dd class="flex flex-wrap items-center gap-2">
                        <MaskedAccount
                            :masked="summary.payee?.masked"
                            :last4="summary.payee?.last4"
                        />
                        <span
                            v-if="
                                summary.payee?.bank_name ||
                                summary.payee?.bank_country
                            "
                            class="text-xs text-muted-foreground"
                            >{{
                                [
                                    summary.payee?.bank_name,
                                    summary.payee?.bank_country,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}</span
                        >
                    </dd>
                    <template v-if="summary.verification">
                        <dt class="text-muted-foreground">
                            {{ t('scampause.fields.verification') }}
                        </dt>
                        <dd>{{ summary.verification }}</dd>
                    </template>
                    <template v-if="summary.policy">
                        <dt class="text-muted-foreground">
                            {{ t('scampause.fields.policy') }}
                        </dt>
                        <dd>{{ summary.policy }}</dd>
                    </template>
                </dl>
                <ul
                    v-if="summary.signals.length"
                    class="list-inside list-disc space-y-0.5 text-sm"
                >
                    <li v-for="signal in summary.signals" :key="signal">
                        {{ signal }}
                    </li>
                </ul>
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <fieldset v-if="mode === 'reject'" class="space-y-2">
                    <legend class="mb-1 text-sm font-medium">
                        {{ t('scampause.decision.reason') }}
                    </legend>
                    <RadioGroup
                        v-model="form.reason"
                        class="grid gap-1.5 sm:grid-cols-2"
                        :aria-label="t('scampause.decision.reason')"
                    >
                        <Label
                            v-for="reason in reasons"
                            :key="reason.value"
                            :for="`reason-${reason.value}`"
                            class="flex min-h-10 cursor-pointer items-center gap-2 rounded-md border px-3 py-2 font-normal has-[[data-state=checked]]:border-ring has-[[data-state=checked]]:bg-accent"
                        >
                            <RadioGroupItem
                                :id="`reason-${reason.value}`"
                                :value="reason.value"
                            />
                            {{ reason.label }}
                        </Label>
                    </RadioGroup>
                    <p
                        v-if="form.errors.reason"
                        class="text-sm text-destructive"
                        role="alert"
                    >
                        {{ form.errors.reason }}
                    </p>
                </fieldset>

                <FormField
                    id="decision-comment"
                    :label="t('scampause.decision.comment')"
                    :error="form.errors.comment"
                    :optional="!commentRequired"
                    :optional-label="t('scampause.common.optional')"
                >
                    <template #default="{ describedby, invalid: fieldInvalid }">
                        <Textarea
                            id="decision-comment"
                            v-model="form.comment"
                            rows="3"
                            maxlength="2000"
                            :aria-describedby="describedby"
                            :aria-invalid="fieldInvalid || undefined"
                        />
                    </template>
                </FormField>

                <DialogFooter class="items-center gap-2 sm:justify-between">
                    <p
                        class="hidden items-center gap-1.5 text-xs text-muted-foreground sm:flex"
                    >
                        <template v-if="canApprove">
                            <Kbd>A</Kbd>
                            {{ t('scampause.decision.key_approve') }}
                        </template>
                        <Kbd>R</Kbd> {{ t('scampause.decision.key_reject') }}
                    </p>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row">
                        <Button
                            v-if="mode === 'approve'"
                            type="button"
                            variant="destructive-outline"
                            @click="mode = 'reject'"
                        >
                            <OctagonX aria-hidden="true" />
                            {{ t('scampause.actions.reject') }}
                        </Button>
                        <Button
                            v-else-if="canApprove"
                            type="button"
                            variant="outline"
                            @click="mode = 'approve'"
                        >
                            {{ t('scampause.decision.back_to_approve') }}
                        </Button>
                        <Button
                            type="submit"
                            :variant="
                                mode === 'approve' ? 'default' : 'destructive'
                            "
                            :disabled="form.processing || invalid"
                            :aria-busy="form.processing"
                            data-test="decision-confirm"
                        >
                            <Spinner v-if="form.processing" />
                            <CheckCheck
                                v-else-if="mode === 'approve'"
                                aria-hidden="true"
                            />
                            <OctagonX v-else aria-hidden="true" />
                            {{
                                mode === 'approve'
                                    ? t('scampause.decision.approve_confirm')
                                    : t('scampause.decision.reject_confirm')
                            }}
                        </Button>
                    </div>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
