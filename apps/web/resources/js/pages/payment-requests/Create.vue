<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { FileUp, Gauge, Keyboard, ScanSearch } from '@lucide/vue';
import { computed, ref } from 'vue';
import UsageMeter from '@/components/common/UsageMeter.vue';
import AccountInputs from '@/components/domain/AccountInputs.vue';
import type { AccountDraft } from '@/components/domain/AccountInputs.vue';
import FileDropzone from '@/components/domain/FileDropzone.vue';
import FormField from '@/components/domain/FormField.vue';
import StopCallConfirm from '@/components/domain/StopCallConfirm.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/composables/useCan';
import { accountChecksum, routingRequired } from '@/lib/domain/bank-validators';
import { normalizeAmountInput, parseAmount } from '@/lib/domain/money';
import { sp } from '@/lib/domain/routes';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import type {
    PaymentRequestCreateProps,
    PaymentRequestKind,
} from '@/types/domain';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'scampause.nav.requests', href: sp.requests.index() },
            { title: 'scampause.nav.new_check', href: sp.requests.create() },
        ],
    },
});

const props = defineProps<PaymentRequestCreateProps>();

const { can } = useCan();
const tab = ref<'upload' | 'manual'>('upload');

const limitReached = computed(
    () => props.limit.limit !== null && props.limit.used >= props.limit.limit,
);

const upload = useForm<{
    files: File[];
    kind: PaymentRequestKind | '';
    vendor_id: string;
    description: string;
}>({
    files: [],
    kind: '',
    vendor_id: '',
    description: '',
});

const uploadProgress = computed(() =>
    upload.progress ? Math.round(upload.progress.percentage ?? 0) : null,
);

const uploadErrors = computed(() => {
    const errors = upload.errors as Record<string, string | undefined>;

    return Object.entries(errors)
        .filter(([key]) => key.startsWith('files'))
        .map(([, value]) => value)
        .filter((value): value is string => Boolean(value));
});

function submitUpload(): void {
    if (!upload.files.length || upload.processing || limitReached.value) {
        return;
    }

    upload
        .transform((data) => ({
            files: data.files,
            kind: data.kind || null,
            vendor_id: data.vendor_id ? Number(data.vendor_id) : null,
            description: data.description || null,
        }))
        .post(sp.requests.store().url, { forceFormData: true });
}

type ManualForm = {
    title: string;
    kind: PaymentRequestKind;
    vendor_id: string;
    amount: string;
    currency: string;
    invoice_number: string;
    invoice_date: string;
    due_date: string;
    payee: AccountDraft;
    sender_email: string;
    description: string;
};

const manual = useForm<ManualForm>({
    title: '',
    kind: 'invoice',
    vendor_id: '',
    amount: '',
    currency: props.base_currency,
    invoice_number: '',
    invoice_date: '',
    due_date: '',
    payee: {
        scheme: props.base_currency === 'RUB' ? 'ru_account' : 'iban',
        account_number: '',
        routing_code: '',
        bic: '',
        account_holder: '',
        bank_name: '',
    },
    sender_email: '',
    description: '',
});

const manualErrors = computed(
    () => manual.errors as Record<string, string | undefined>,
);

const amountError = computed(() => {
    if (!manual.amount.trim()) {
        return null;
    }

    const minor = parseAmount(manual.amount, manual.currency);

    return minor === null || minor <= 0
        ? t('scampause.intake.amount_invalid')
        : null;
});

const accountInvalid = computed(
    () =>
        manual.payee.account_number.trim() !== '' &&
        accountChecksum(
            manual.payee.scheme,
            manual.payee.account_number,
            manual.payee.routing_code,
        ) === 'invalid',
);

const manualBlocked = computed(
    () =>
        limitReached.value ||
        manual.title.trim().length < 3 ||
        !manual.amount.trim() ||
        amountError.value !== null ||
        (manual.kind !== 'other' && !manual.payee.account_number.trim()) ||
        (routingRequired(manual.payee.scheme) &&
            manual.payee.account_number.trim() !== '' &&
            !manual.payee.routing_code.trim()),
);

function submitManual(): void {
    if (manualBlocked.value || manual.processing) {
        return;
    }

    manual
        .transform((data) => ({
            title: data.title.trim(),
            kind: data.kind,
            vendor_id: data.vendor_id ? Number(data.vendor_id) : null,
            amount: normalizeAmountInput(data.amount),
            currency: data.currency,
            invoice_number: data.invoice_number || null,
            invoice_date: data.invoice_date || null,
            due_date: data.due_date || null,
            payee: {
                scheme: data.payee.scheme,
                account_number: data.payee.account_number || null,
                routing_code: data.payee.routing_code || null,
                bic: data.payee.bic || null,
                account_holder: data.payee.account_holder || null,
                bank_name: data.payee.bank_name || null,
            },
            sender_email: data.sender_email || null,
            description: data.description || null,
        }))
        .post(sp.requests.manual().url);
}
</script>

<template>
    <Head :title="t('scampause.intake.title')" />

    <div
        class="mx-auto grid w-full max-w-[90rem] gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:py-8"
        data-test="intake-page"
    >
        <div class="min-w-0 space-y-6">
            <PageHeader
                :icon="ScanSearch"
                :eyebrow="t('scampause.intake.eyebrow')"
                :title="t('scampause.intake.title')"
                :description="t('scampause.intake.description')"
            />

            <section
                v-if="limitReached"
                class="flex flex-col gap-3 rounded-xl border border-info/30 bg-info-soft p-4 text-info-soft-foreground sm:flex-row sm:items-center sm:justify-between"
                data-test="limit-card"
            >
                <div class="flex items-start gap-3">
                    <Gauge class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                    <div>
                        <p class="font-semibold">
                            {{ t('scampause.intake.limit_title') }}
                        </p>
                        <p class="text-sm text-pretty">
                            {{ t('scampause.intake.limit_body') }}
                        </p>
                    </div>
                </div>
                <Button v-if="can('billing.manage')" as-child variant="outline">
                    <Link :href="endpoints.settings.billing.show()">{{
                        t('scampause.banners.limit_action')
                    }}</Link>
                </Button>
            </section>

            <UsageMeter
                v-else-if="limit.limit !== null"
                :label="t('scampause.intake.usage')"
                :entry="{ used: limit.used, limit: limit.limit }"
            />

            <Tabs v-model="tab" class="gap-4">
                <TabsList
                    class="grid w-full grid-cols-2 sm:inline-grid sm:w-auto"
                >
                    <TabsTrigger value="upload" data-test="tab-upload">
                        <FileUp aria-hidden="true" />
                        {{ t('scampause.intake.tab_upload') }}
                    </TabsTrigger>
                    <TabsTrigger value="manual" data-test="tab-manual">
                        <Keyboard aria-hidden="true" />
                        {{ t('scampause.intake.tab_manual') }}
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="upload">
                    <form
                        class="space-y-5"
                        data-test="upload-form"
                        @submit.prevent="submitUpload"
                    >
                        <FileDropzone
                            v-model="upload.files"
                            :accept="accept"
                            :max-files="maxFiles"
                            :max-size-mb="maxSizeMb"
                            :disabled="upload.processing || limitReached"
                            :progress="
                                upload.processing ? uploadProgress : null
                            "
                        />
                        <ul v-if="uploadErrors.length" role="alert">
                            <li
                                v-for="error in uploadErrors"
                                :key="error"
                                class="text-sm text-destructive"
                            >
                                {{ error }}
                            </li>
                        </ul>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField
                                id="upload-kind"
                                :label="t('scampause.fields.kind')"
                                :error="upload.errors.kind"
                                optional
                                :optional-label="
                                    t('scampause.intake.detect_auto')
                                "
                            >
                                <Select v-model="upload.kind">
                                    <SelectTrigger
                                        id="upload-kind"
                                        class="w-full"
                                    >
                                        <SelectValue
                                            :placeholder="
                                                t(
                                                    'scampause.intake.detect_auto',
                                                )
                                            "
                                        />
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
                            <FormField
                                id="upload-vendor"
                                :label="t('scampause.fields.vendor')"
                                :error="upload.errors.vendor_id"
                                optional
                                :optional-label="
                                    t('scampause.intake.detect_auto')
                                "
                            >
                                <Select v-model="upload.vendor_id">
                                    <SelectTrigger
                                        id="upload-vendor"
                                        class="w-full"
                                    >
                                        <SelectValue
                                            :placeholder="
                                                t(
                                                    'scampause.intake.detect_auto',
                                                )
                                            "
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="vendor in vendors"
                                            :key="vendor.id"
                                            :value="String(vendor.id)"
                                            >{{ vendor.name }}</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                id="upload-description"
                                :label="t('scampause.fields.description')"
                                :error="upload.errors.description"
                                optional
                                :optional-label="t('scampause.common.optional')"
                                class="sm:col-span-2"
                            >
                                <Textarea
                                    id="upload-description"
                                    v-model="upload.description"
                                    rows="3"
                                    maxlength="2000"
                                    :placeholder="
                                        t(
                                            'scampause.intake.description_placeholder',
                                        )
                                    "
                                />
                            </FormField>
                        </div>
                        <div class="flex justify-end">
                            <Button
                                type="submit"
                                size="lg"
                                :disabled="
                                    !upload.files.length ||
                                    upload.processing ||
                                    limitReached
                                "
                                :aria-busy="upload.processing"
                                data-test="upload-submit"
                            >
                                <Spinner v-if="upload.processing" />
                                <ScanSearch v-else aria-hidden="true" />
                                {{ t('scampause.intake.submit_upload') }}
                            </Button>
                        </div>
                    </form>
                </TabsContent>

                <TabsContent value="manual">
                    <form
                        class="space-y-6 rounded-xl border bg-card p-4 sm:p-5"
                        data-test="manual-form"
                        novalidate
                        @submit.prevent="submitManual"
                    >
                        <fieldset class="grid gap-4 sm:grid-cols-2">
                            <legend
                                class="mb-3 eyebrow text-[0.6875rem] text-muted-foreground"
                            >
                                {{ t('scampause.intake.section_request') }}
                            </legend>
                            <FormField
                                id="manual-title"
                                :label="t('scampause.fields.title')"
                                :error="manual.errors.title"
                                class="sm:col-span-2"
                            >
                                <template #default="{ describedby, invalid }">
                                    <Input
                                        id="manual-title"
                                        v-model="manual.title"
                                        maxlength="200"
                                        required
                                        :aria-describedby="describedby"
                                        :aria-invalid="invalid || undefined"
                                        data-test="manual-title"
                                    />
                                </template>
                            </FormField>
                            <FormField
                                id="manual-kind"
                                :label="t('scampause.fields.kind')"
                                :error="manual.errors.kind"
                            >
                                <Select v-model="manual.kind">
                                    <SelectTrigger
                                        id="manual-kind"
                                        class="w-full"
                                    >
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
                            <FormField
                                id="manual-vendor"
                                :label="t('scampause.fields.vendor')"
                                :error="manual.errors.vendor_id"
                                optional
                                :optional-label="t('scampause.common.optional')"
                            >
                                <Select v-model="manual.vendor_id">
                                    <SelectTrigger
                                        id="manual-vendor"
                                        class="w-full"
                                    >
                                        <SelectValue
                                            :placeholder="
                                                t('scampause.common.choose')
                                            "
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="vendor in vendors"
                                            :key="vendor.id"
                                            :value="String(vendor.id)"
                                            >{{ vendor.name }}</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <div
                                class="grid grid-cols-[minmax(0,1fr)_7rem] gap-2 sm:col-span-2"
                            >
                                <FormField
                                    id="manual-amount"
                                    :label="t('scampause.fields.amount')"
                                    :error="manual.errors.amount ?? amountError"
                                >
                                    <template
                                        #default="{ describedby, invalid }"
                                    >
                                        <Input
                                            id="manual-amount"
                                            v-model="manual.amount"
                                            inputmode="decimal"
                                            autocomplete="off"
                                            class="text-right tabular-nums"
                                            :aria-describedby="describedby"
                                            :aria-invalid="invalid || undefined"
                                            data-test="manual-amount"
                                        />
                                    </template>
                                </FormField>
                                <FormField
                                    id="manual-currency"
                                    :label="t('scampause.fields.currency')"
                                    :error="manual.errors.currency"
                                >
                                    <Select v-model="manual.currency">
                                        <SelectTrigger
                                            id="manual-currency"
                                            class="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="currency in currencies"
                                                :key="currency"
                                                :value="currency"
                                                >{{ currency }}</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </FormField>
                            </div>
                            <FormField
                                id="manual-invoice"
                                :label="t('scampause.fields.invoice_number')"
                                :error="manual.errors.invoice_number"
                                optional
                                :optional-label="t('scampause.common.optional')"
                            >
                                <Input
                                    id="manual-invoice"
                                    v-model="manual.invoice_number"
                                    maxlength="64"
                                    class="font-mono"
                                />
                            </FormField>
                            <FormField
                                id="manual-sender"
                                :label="t('scampause.fields.sender_email')"
                                :error="manual.errors.sender_email"
                                optional
                                :optional-label="t('scampause.common.optional')"
                            >
                                <Input
                                    id="manual-sender"
                                    v-model="manual.sender_email"
                                    type="email"
                                    autocomplete="off"
                                />
                            </FormField>
                            <FormField
                                id="manual-invoice-date"
                                :label="t('scampause.fields.invoice_date')"
                                :error="manual.errors.invoice_date"
                                optional
                                :optional-label="t('scampause.common.optional')"
                            >
                                <Input
                                    id="manual-invoice-date"
                                    v-model="manual.invoice_date"
                                    type="date"
                                />
                            </FormField>
                            <FormField
                                id="manual-due-date"
                                :label="t('scampause.fields.due_date')"
                                :error="manual.errors.due_date"
                                optional
                                :optional-label="t('scampause.common.optional')"
                            >
                                <Input
                                    id="manual-due-date"
                                    v-model="manual.due_date"
                                    type="date"
                                />
                            </FormField>
                        </fieldset>

                        <fieldset class="space-y-3">
                            <legend
                                class="mb-3 eyebrow text-[0.6875rem] text-muted-foreground"
                            >
                                {{ t('scampause.intake.section_payee') }}
                            </legend>
                            <AccountInputs
                                v-model="manual.payee"
                                id-prefix="manual-payee"
                                :errors="manualErrors"
                                error-prefix="payee."
                                :account-optional="manual.kind === 'other'"
                            />
                            <p
                                v-if="accountInvalid"
                                class="text-sm text-pretty text-hazard-ink"
                            >
                                {{ t('scampause.intake.checksum_warning') }}
                            </p>
                        </fieldset>

                        <FormField
                            id="manual-description"
                            :label="t('scampause.fields.description')"
                            :error="manual.errors.description"
                            optional
                            :optional-label="t('scampause.common.optional')"
                        >
                            <Textarea
                                id="manual-description"
                                v-model="manual.description"
                                rows="3"
                                maxlength="2000"
                            />
                        </FormField>

                        <div class="flex justify-end">
                            <Button
                                type="submit"
                                size="lg"
                                :disabled="manualBlocked || manual.processing"
                                :aria-busy="manual.processing"
                                data-test="manual-submit"
                            >
                                <Spinner v-if="manual.processing" />
                                <ScanSearch v-else aria-hidden="true" />
                                {{ t('scampause.intake.submit_manual') }}
                            </Button>
                        </div>
                    </form>
                </TabsContent>
            </Tabs>
        </div>

        <div class="lg:sticky lg:top-20 lg:self-start">
            <StopCallConfirm />
        </div>
    </div>
</template>
