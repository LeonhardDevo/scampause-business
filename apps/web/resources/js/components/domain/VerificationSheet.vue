<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Ban, PhoneCall } from '@lucide/vue';
import { computed, watch } from 'vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import CopyButton from '@/components/common/CopyButton.vue';
import FormField from '@/components/domain/FormField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { enumLabel } from '@/lib/domain/status';
import { t } from '@/lib/i18n';
import type {
    CallbackContact,
    ChecklistKey,
    Option,
    VerificationMethod,
    VerificationOutcome,
} from '@/types/domain';

const props = withDefaults(
    defineProps<{
        action: string;
        methods: Array<Option<VerificationMethod> & { allowed: boolean }>;
        contacts: CallbackContact[];
        checklist?: ChecklistKey[];
        requireLast4?: boolean;
        title?: string | null;
        replaceable?: Array<{ id: number; label: string }>;
    }>(),
    {
        checklist: () => [
            'account_last4',
            'amount',
            'account_holder',
            'change_initiated_by_vendor',
            'invoice_number',
        ],
        requireLast4: true,
        title: null,
        replaceable: () => [],
    },
);

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ done: [] }>();

type VerificationFormData = {
    method: VerificationMethod | '';
    contact_id: string;
    spoken_to_name: string;
    outcome: VerificationOutcome;
    occurred_at: string;
    checklist: Record<ChecklistKey, boolean>;
    decision_reason: '' | 'fraud_confirmed' | 'details_mismatch';
    replaces_account_id: string;
    notes: string;
};

function localNow(): string {
    const now = new Date();
    now.setSeconds(0, 0);

    return new Date(now.getTime() - now.getTimezoneOffset() * 60_000)
        .toISOString()
        .slice(0, 16);
}

function blankChecklist(): Record<ChecklistKey, boolean> {
    return {
        account_last4: false,
        amount: false,
        account_holder: false,
        change_initiated_by_vendor: false,
        invoice_number: false,
    };
}

const form = useForm<VerificationFormData>({
    method: '',
    contact_id: '',
    spoken_to_name: '',
    outcome: 'confirmed',
    occurred_at: localNow(),
    checklist: blankChecklist(),
    decision_reason: '',
    replaces_account_id: '',
    notes: '',
});

const allowedMethods = computed(() =>
    props.methods.filter((method) => method.allowed),
);

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
        form.occurred_at = localNow();
        form.checklist = blankChecklist();
        form.method = allowedMethods.value[0]?.value ?? '';
        form.contact_id = props.contacts[0] ? String(props.contacts[0].id) : '';
    }
});

const needsContact = computed(() => form.method !== 'in_person');

const outcomes = computed<Array<{ value: VerificationOutcome; label: string }>>(
    () =>
        (['confirmed', 'denied', 'unreachable', 'inconclusive'] as const).map(
            (value) => ({
                value,
                label: enumLabel('verification_outcome', value),
            }),
        ),
);

const maxLocal = computed(() => localNow());

const blocked = computed(
    () =>
        (needsContact.value && props.contacts.length === 0) ||
        !form.method ||
        form.spoken_to_name.trim().length < 2 ||
        (form.outcome === 'denied' && !form.decision_reason) ||
        (form.outcome === 'confirmed' &&
            props.requireLast4 &&
            props.checklist.includes('account_last4') &&
            !form.checklist.account_last4),
);

function checklistError(key: ChecklistKey): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;

    return errors[`checklist.${key}`];
}

function submit(): void {
    if (blocked.value || form.processing) {
        return;
    }

    form.transform((data) => ({
        method: data.method,
        contact_id:
            needsContact.value && data.contact_id
                ? Number(data.contact_id)
                : null,
        spoken_to_name: data.spoken_to_name.trim(),
        outcome: data.outcome,
        occurred_at: new Date(data.occurred_at).toISOString(),
        checklist: Object.fromEntries(
            props.checklist.map((key) => [key, data.checklist[key]]),
        ),
        decision_reason:
            data.outcome === 'denied' ? data.decision_reason || null : null,
        replaces_account_id: data.replaces_account_id
            ? Number(data.replaces_account_id)
            : null,
        notes: data.notes || null,
    })).post(props.action, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('done');
        },
    });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-lg"
            data-test="verification-sheet"
        >
            <SheetHeader class="border-b px-5 py-4">
                <SheetTitle class="flex items-center gap-2">
                    <PhoneCall class="size-4" aria-hidden="true" />
                    {{ title ?? t('scampause.verification.sheet_title') }}
                </SheetTitle>
                <SheetDescription>{{
                    t('scampause.verification.sheet_body')
                }}</SheetDescription>
            </SheetHeader>

            <form
                id="verification-form"
                class="flex-1 space-y-5 px-5 py-5"
                @submit.prevent="submit"
            >
                <p
                    class="flex items-start gap-2 rounded-md border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground"
                >
                    <Ban class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    {{ t('scampause.scc.never_message_numbers') }}
                </p>

                <FormField
                    id="verification-method"
                    :label="t('scampause.verification.method')"
                    :error="form.errors.method"
                >
                    <Select v-model="form.method">
                        <SelectTrigger
                            id="verification-method"
                            class="w-full"
                            data-test="verification-method"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="method in methods"
                                :key="method.value"
                                :value="method.value"
                                :disabled="!method.allowed"
                            >
                                {{ method.label }}
                                <template v-if="!method.allowed">
                                    ·
                                    {{
                                        t('scampause.verification.not_allowed')
                                    }}</template
                                >
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>

                <fieldset v-if="needsContact" class="space-y-2">
                    <legend class="text-sm font-medium">
                        {{ t('scampause.verification.contact') }}
                    </legend>
                    <p
                        v-if="!contacts.length"
                        class="rounded-md border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground"
                        data-test="no-contacts"
                    >
                        {{ t('scampause.verification.no_contacts') }}
                    </p>
                    <RadioGroup
                        v-else
                        v-model="form.contact_id"
                        class="grid gap-2"
                        :aria-label="t('scampause.verification.contact')"
                    >
                        <Label
                            v-for="contact in contacts"
                            :key="contact.id"
                            :for="`contact-${contact.id}`"
                            class="flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 font-normal has-[[data-state=checked]]:border-ring has-[[data-state=checked]]:bg-accent"
                        >
                            <RadioGroupItem
                                :id="`contact-${contact.id}`"
                                :value="String(contact.id)"
                                class="mt-1"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium">{{
                                    contact.name
                                }}</span>
                                <span class="block font-mono tabular-nums">{{
                                    contact.phone_display
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        enumLabel(
                                            'contact_source',
                                            contact.source,
                                        )
                                    }}<template v-if="contact.source_ref">
                                        · {{ contact.source_ref }}</template
                                    ></span
                                >
                            </span>
                            <CopyButton
                                :value="contact.phone_display"
                                :label="t('scampause.scc.copy_number')"
                            />
                        </Label>
                    </RadioGroup>
                    <p
                        v-if="form.errors.contact_id"
                        class="text-sm text-destructive"
                        role="alert"
                    >
                        {{ form.errors.contact_id }}
                    </p>
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        id="verification-spoken"
                        :label="t('scampause.verification.spoken_to')"
                        :error="form.errors.spoken_to_name"
                    >
                        <template #default="{ describedby, invalid }">
                            <Input
                                id="verification-spoken"
                                v-model="form.spoken_to_name"
                                maxlength="160"
                                autocomplete="off"
                                :aria-describedby="describedby"
                                :aria-invalid="invalid || undefined"
                                data-test="verification-spoken"
                            />
                        </template>
                    </FormField>
                    <FormField
                        id="verification-at"
                        :label="t('scampause.verification.occurred_at')"
                        :error="form.errors.occurred_at"
                    >
                        <template #default="{ describedby, invalid }">
                            <Input
                                id="verification-at"
                                v-model="form.occurred_at"
                                type="datetime-local"
                                :max="maxLocal"
                                :aria-describedby="describedby"
                                :aria-invalid="invalid || undefined"
                            />
                        </template>
                    </FormField>
                </div>

                <div class="space-y-1.5">
                    <p class="text-sm font-medium">
                        {{ t('scampause.verification.outcome') }}
                    </p>
                    <SegmentedControl
                        v-model="form.outcome"
                        :options="outcomes"
                        :label="t('scampause.verification.outcome')"
                    />
                    <p
                        v-if="form.errors.outcome"
                        class="text-sm text-destructive"
                        role="alert"
                    >
                        {{ form.errors.outcome }}
                    </p>
                </div>

                <FormField
                    v-if="form.outcome === 'denied'"
                    id="verification-reason"
                    :label="t('scampause.verification.denied_reason')"
                    :error="form.errors.decision_reason"
                >
                    <Select v-model="form.decision_reason">
                        <SelectTrigger id="verification-reason" class="w-full">
                            <SelectValue
                                :placeholder="t('scampause.common.choose')"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="fraud_confirmed">{{
                                enumLabel('decision_reason', 'fraud_confirmed')
                            }}</SelectItem>
                            <SelectItem value="details_mismatch">{{
                                enumLabel('decision_reason', 'details_mismatch')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>

                <fieldset v-if="checklist.length" class="space-y-2">
                    <legend class="text-sm font-medium">
                        {{ t('scampause.verification.checklist') }}
                    </legend>
                    <div v-for="key in checklist" :key="key" class="space-y-1">
                        <Label
                            :for="`check-${key}`"
                            class="flex min-h-11 cursor-pointer items-center gap-3 rounded-md border px-3 py-2 font-normal"
                        >
                            <Checkbox
                                :id="`check-${key}`"
                                v-model="form.checklist[key]"
                                class="size-5"
                                :data-test="`check-${key}`"
                            />
                            {{ t(`domain.checklist.${key}`) }}
                        </Label>
                        <p
                            v-if="checklistError(key)"
                            class="text-sm text-destructive"
                            role="alert"
                        >
                            {{ checklistError(key) }}
                        </p>
                    </div>
                </fieldset>

                <FormField
                    v-if="replaceable.length && form.outcome === 'confirmed'"
                    id="verification-replaces"
                    :label="t('scampause.verification.replaces')"
                    :error="form.errors.replaces_account_id"
                    optional
                    :optional-label="t('scampause.common.optional')"
                >
                    <Select v-model="form.replaces_account_id">
                        <SelectTrigger
                            id="verification-replaces"
                            class="w-full"
                        >
                            <SelectValue
                                :placeholder="
                                    t('scampause.verification.replaces_none')
                                "
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="account in replaceable"
                                :key="account.id"
                                :value="String(account.id)"
                                >{{ account.label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </FormField>

                <FormField
                    id="verification-notes"
                    :label="t('scampause.verification.notes')"
                    :error="form.errors.notes"
                    optional
                    :optional-label="t('scampause.common.optional')"
                >
                    <Textarea
                        id="verification-notes"
                        v-model="form.notes"
                        rows="3"
                        maxlength="2000"
                    />
                </FormField>
            </form>

            <SheetFooter class="border-t px-5 py-4">
                <Button
                    type="submit"
                    form="verification-form"
                    :disabled="blocked || form.processing"
                    :aria-busy="form.processing"
                    data-test="verification-submit"
                >
                    <Spinner v-if="form.processing" />
                    <PhoneCall v-else aria-hidden="true" />
                    {{ t('scampause.verification.submit') }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
