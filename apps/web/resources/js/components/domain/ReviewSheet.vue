<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { PencilLine } from '@lucide/vue';
import { computed, watch } from 'vue';
import AccountInputs from '@/components/domain/AccountInputs.vue';
import type { AccountDraft } from '@/components/domain/AccountInputs.vue';
import FormField from '@/components/domain/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import {
    minorToInput,
    normalizeAmountInput,
    parseAmount,
} from '@/lib/domain/money';
import { sp } from '@/lib/domain/routes';
import { t } from '@/lib/i18n';
import type { CaseRequest, Option, PaymentRequestKind } from '@/types/domain';

const props = defineProps<{
    request: CaseRequest;
    kinds: Option<PaymentRequestKind>[];
}>();

const open = defineModel<boolean>('open', { default: false });

type ReviewForm = {
    title: string;
    kind: PaymentRequestKind;
    amount: string;
    currency: string;
    invoice_number: string;
    invoice_date: string;
    due_date: string;
    payee: AccountDraft;
    description: string;
};

function initial(): ReviewForm {
    const request = props.request;
    const currency = request.amount?.currency ?? 'USD';

    return {
        title: request.title,
        kind: request.kind,
        amount: request.amount
            ? minorToInput(request.amount.minor, currency)
            : '',
        currency,
        invoice_number: request.invoice_number ?? '',
        invoice_date: request.invoice_date ?? '',
        due_date: request.due_date ?? '',
        payee: {
            scheme: request.payee?.scheme ?? 'iban',
            account_number: '',
            routing_code: '',
            bic: request.payee?.bic ?? '',
            account_holder: '',
            bank_name: request.payee?.bank_name ?? '',
        },
        description: request.description ?? '',
    };
}

const form = useForm<ReviewForm>(initial());

watch(open, (value) => {
    if (value) {
        form.defaults(initial());
        form.reset();
        form.clearErrors();
    }
});

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const amountError = computed(() => {
    if (!form.amount.trim()) {
        return null;
    }

    const minor = parseAmount(form.amount, form.currency);

    return minor === null || minor <= 0
        ? t('scampause.intake.amount_invalid')
        : null;
});

function submit(): void {
    if (form.processing || amountError.value) {
        return;
    }

    form.transform((data) => {
        const payload: Record<string, unknown> = {
            title: data.title.trim(),
            kind: data.kind,
            invoice_number: data.invoice_number || null,
            invoice_date: data.invoice_date || null,
            due_date: data.due_date || null,
            description: data.description || null,
        };

        if (data.amount.trim()) {
            payload.amount = normalizeAmountInput(data.amount);
            payload.currency = data.currency.toUpperCase();
        }

        if (data.payee.account_number.trim()) {
            payload.payee = {
                scheme: data.payee.scheme,
                account_number: data.payee.account_number,
                routing_code: data.payee.routing_code || null,
                bic: data.payee.bic || null,
                account_holder: data.payee.account_holder || null,
                bank_name: data.payee.bank_name || null,
            };
        }

        return payload;
    }).patch(sp.requests.update(props.request.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-xl"
            data-test="review-sheet"
        >
            <SheetHeader class="border-b px-5 py-4">
                <SheetTitle class="flex items-center gap-2">
                    <PencilLine class="size-4" aria-hidden="true" />
                    {{ t('scampause.review.title') }}
                </SheetTitle>
                <SheetDescription>{{
                    t('scampause.review.body')
                }}</SheetDescription>
            </SheetHeader>
            <form
                id="review-form"
                class="flex-1 space-y-5 px-5 py-5"
                @submit.prevent="submit"
            >
                <FormField
                    id="review-title"
                    :label="t('scampause.fields.title')"
                    :error="form.errors.title"
                >
                    <Input
                        id="review-title"
                        v-model="form.title"
                        maxlength="200"
                    />
                </FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        id="review-kind"
                        :label="t('scampause.fields.kind')"
                        :error="form.errors.kind"
                    >
                        <Select v-model="form.kind">
                            <SelectTrigger id="review-kind" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="kind in kinds"
                                    :key="kind.value"
                                    :value="kind.value"
                                    >{{ kind.label }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </FormField>
                    <div class="grid grid-cols-[minmax(0,1fr)_5.5rem] gap-2">
                        <FormField
                            id="review-amount"
                            :label="t('scampause.fields.amount')"
                            :error="form.errors.amount ?? amountError"
                        >
                            <Input
                                id="review-amount"
                                v-model="form.amount"
                                inputmode="decimal"
                                class="text-right tabular-nums"
                            />
                        </FormField>
                        <FormField
                            id="review-currency"
                            :label="t('scampause.fields.currency')"
                            :error="form.errors.currency"
                        >
                            <Input
                                id="review-currency"
                                v-model="form.currency"
                                maxlength="3"
                                class="font-mono uppercase"
                            />
                        </FormField>
                    </div>
                    <FormField
                        id="review-invoice"
                        :label="t('scampause.fields.invoice_number')"
                        :error="form.errors.invoice_number"
                    >
                        <Input
                            id="review-invoice"
                            v-model="form.invoice_number"
                            maxlength="64"
                            class="font-mono"
                        />
                    </FormField>
                    <FormField
                        id="review-invoice-date"
                        :label="t('scampause.fields.invoice_date')"
                        :error="form.errors.invoice_date"
                    >
                        <Input
                            id="review-invoice-date"
                            v-model="form.invoice_date"
                            type="date"
                        />
                    </FormField>
                    <FormField
                        id="review-due"
                        :label="t('scampause.fields.due_date')"
                        :error="form.errors.due_date"
                    >
                        <Input
                            id="review-due"
                            v-model="form.due_date"
                            type="date"
                        />
                    </FormField>
                </div>
                <fieldset class="space-y-2">
                    <legend
                        class="eyebrow text-[0.6875rem] text-muted-foreground"
                    >
                        {{ t('scampause.intake.section_payee') }}
                    </legend>
                    <p class="text-sm text-pretty text-muted-foreground">
                        {{ t('scampause.review.payee_hint') }}
                    </p>
                    <AccountInputs
                        v-model="form.payee"
                        id-prefix="review-payee"
                        :errors="errors"
                        error-prefix="payee."
                        account-optional
                    />
                </fieldset>
                <FormField
                    id="review-description"
                    :label="t('scampause.fields.description')"
                    :error="form.errors.description"
                    optional
                    :optional-label="t('scampause.common.optional')"
                >
                    <Textarea
                        id="review-description"
                        v-model="form.description"
                        rows="3"
                        maxlength="2000"
                    />
                </FormField>
            </form>
            <SheetFooter class="border-t px-5 py-4">
                <Button
                    type="submit"
                    form="review-form"
                    :disabled="
                        form.processing || !form.isDirty || Boolean(amountError)
                    "
                    :aria-busy="form.processing"
                    data-test="review-submit"
                >
                    <Spinner v-if="form.processing" />
                    {{ t('scampause.review.save') }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
