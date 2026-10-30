<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Building2, Save } from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import TagsInput from '@/components/common/TagsInput.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { sp } from '@/lib/domain/routes';
import { enumLabel } from '@/lib/domain/status';
import { t } from '@/lib/i18n';
import type { VendorFormProps, VendorKind, VendorStatus } from '@/types/domain';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'scampause.nav.vendors', href: sp.vendors.index() },
        ],
    },
});

const props = defineProps<VendorFormProps>();

const editing = computed(() => props.vendor !== null);

const form = useForm({
    kind: (props.vendor?.kind ?? 'supplier') as VendorKind,
    name: props.vendor?.name ?? '',
    legal_name: props.vendor?.legal_name ?? '',
    aliases: [...(props.vendor?.aliases ?? [])],
    tax_id: props.vendor?.tax_id ?? '',
    country: props.vendor?.country ?? '',
    default_currency: props.vendor?.default_currency ?? '',
    status: (props.vendor?.status ?? 'active') as VendorStatus,
    payment_terms_days:
        props.vendor?.payment_terms_days !== null &&
        props.vendor?.payment_terms_days !== undefined
            ? String(props.vendor.payment_terms_days)
            : '',
    notes: props.vendor?.notes ?? '',
    linked_user_id: props.vendor?.linked_user_id
        ? String(props.vendor.linked_user_id)
        : '',
});

const STATUSES: VendorStatus[] = ['active', 'on_hold', 'archived'];

const countryOptions = computed(() =>
    props.countries.map((country) => ({
        value: country.code,
        label: country.name,
        hint: country.code,
    })),
);

const countryModel = computed({
    get: () => form.country || null,
    set: (value: string | null) => {
        form.country = value ?? '';
    },
});

const blocked = computed(() => form.name.trim().length < 2);

function submit(): void {
    if (blocked.value || form.processing) {
        return;
    }

    const transformed = form.transform((data) => ({
        kind: data.kind,
        name: data.name.trim(),
        legal_name: data.legal_name || null,
        aliases: data.aliases,
        tax_id: data.tax_id || null,
        country: data.country || null,
        default_currency: data.default_currency || null,
        status: data.status,
        payment_terms_days:
            data.payment_terms_days === ''
                ? null
                : Number(data.payment_terms_days),
        notes: data.notes || null,
        linked_user_id:
            data.kind === 'employee' && data.linked_user_id
                ? Number(data.linked_user_id)
                : null,
    }));

    if (props.vendor) {
        transformed.patch(sp.vendors.update(props.vendor.id).url);
    } else {
        transformed.post(sp.vendors.store().url);
    }
}
</script>

<template>
    <Head
        :title="
            editing
                ? t('scampause.vendor_form.edit_title')
                : t('scampause.vendor_form.create_title')
        "
    />

    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="Building2"
            :eyebrow="t('scampause.vendors.eyebrow')"
            :title="
                editing
                    ? t('scampause.vendor_form.edit_title')
                    : t('scampause.vendor_form.create_title')
            "
            :description="t('scampause.vendor_form.description')"
        />

        <form
            class="space-y-6"
            data-test="vendor-form"
            novalidate
            @submit.prevent="submit"
        >
            <fieldset
                class="grid gap-4 rounded-xl border bg-card p-4 sm:grid-cols-2 sm:p-5"
            >
                <legend
                    class="px-1 eyebrow text-[0.6875rem] text-muted-foreground"
                >
                    {{ t('scampause.vendor_form.sections.identity') }}
                </legend>
                <FormField
                    id="vendor-kind"
                    :label="t('scampause.fields.kind')"
                    :error="form.errors.kind"
                >
                    <Select v-model="form.kind">
                        <SelectTrigger id="vendor-kind" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
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
                    id="vendor-status"
                    :label="t('scampause.fields.status')"
                    :error="form.errors.status"
                >
                    <Select v-model="form.status">
                        <SelectTrigger id="vendor-status" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="status in STATUSES"
                                :key="status"
                                :value="status"
                                >{{
                                    enumLabel('vendor_status', status)
                                }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </FormField>
                <FormField
                    id="vendor-name"
                    :label="t('scampause.vendor_form.name')"
                    :error="form.errors.name"
                    class="sm:col-span-2"
                >
                    <template #default="{ describedby, invalid }">
                        <Input
                            id="vendor-name"
                            v-model="form.name"
                            maxlength="200"
                            required
                            :aria-describedby="describedby"
                            :aria-invalid="invalid || undefined"
                            data-test="vendor-name"
                        />
                    </template>
                </FormField>
                <FormField
                    id="vendor-legal"
                    :label="t('scampause.vendor_form.legal_name')"
                    :error="form.errors.legal_name"
                    optional
                    :optional-label="t('scampause.common.optional')"
                    class="sm:col-span-2"
                >
                    <Input
                        id="vendor-legal"
                        v-model="form.legal_name"
                        maxlength="200"
                    />
                </FormField>
                <FormField
                    id="vendor-aliases"
                    :label="t('scampause.vendor_form.aliases')"
                    :error="form.errors.aliases"
                    :hint="t('scampause.vendor_form.aliases_hint')"
                    class="sm:col-span-2"
                >
                    <TagsInput
                        id="vendor-aliases"
                        v-model="form.aliases"
                        :max="10"
                        :max-length="120"
                    />
                </FormField>
                <FormField
                    v-if="form.kind === 'employee'"
                    id="vendor-linked"
                    :label="t('scampause.vendor_form.linked_user')"
                    :error="form.errors.linked_user_id"
                    :hint="t('scampause.vendor_form.linked_user_hint')"
                    class="sm:col-span-2"
                >
                    <Select v-model="form.linked_user_id">
                        <SelectTrigger id="vendor-linked" class="w-full">
                            <SelectValue
                                :placeholder="t('scampause.common.choose')"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="member in members"
                                :key="member.id"
                                :value="String(member.id)"
                                >{{ member.name }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </FormField>
            </fieldset>

            <fieldset
                class="grid gap-4 rounded-xl border bg-card p-4 sm:grid-cols-2 sm:p-5"
            >
                <legend
                    class="px-1 eyebrow text-[0.6875rem] text-muted-foreground"
                >
                    {{ t('scampause.vendor_form.sections.tax') }}
                </legend>
                <FormField
                    id="vendor-tax"
                    :label="t('scampause.vendor_form.tax_id')"
                    :error="form.errors.tax_id"
                    optional
                    :optional-label="t('scampause.common.optional')"
                >
                    <Input
                        id="vendor-tax"
                        v-model="form.tax_id"
                        maxlength="32"
                        class="font-mono"
                    />
                </FormField>
                <FormField
                    id="vendor-country"
                    :label="t('scampause.vendor_form.country')"
                    :error="form.errors.country"
                    optional
                    :optional-label="t('scampause.common.optional')"
                >
                    <SearchableSelect
                        id="vendor-country"
                        v-model="countryModel"
                        :options="countryOptions"
                        :placeholder="t('scampause.common.choose')"
                        clearable
                    />
                </FormField>
            </fieldset>

            <fieldset
                class="grid gap-4 rounded-xl border bg-card p-4 sm:grid-cols-2 sm:p-5"
            >
                <legend
                    class="px-1 eyebrow text-[0.6875rem] text-muted-foreground"
                >
                    {{ t('scampause.vendor_form.sections.payment') }}
                </legend>
                <FormField
                    id="vendor-currency"
                    :label="t('scampause.vendor_form.default_currency')"
                    :error="form.errors.default_currency"
                    optional
                    :optional-label="t('scampause.common.optional')"
                >
                    <Select v-model="form.default_currency">
                        <SelectTrigger id="vendor-currency" class="w-full">
                            <SelectValue
                                :placeholder="t('scampause.common.choose')"
                            />
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
                <FormField
                    id="vendor-terms"
                    :label="t('scampause.vendor_form.terms')"
                    :error="form.errors.payment_terms_days"
                    optional
                    :optional-label="t('scampause.common.optional')"
                >
                    <Input
                        id="vendor-terms"
                        v-model="form.payment_terms_days"
                        type="number"
                        min="0"
                        max="365"
                        inputmode="numeric"
                    />
                </FormField>
            </fieldset>

            <fieldset class="rounded-xl border bg-card p-4 sm:p-5">
                <legend
                    class="px-1 eyebrow text-[0.6875rem] text-muted-foreground"
                >
                    {{ t('scampause.vendor_form.sections.notes') }}
                </legend>
                <FormField
                    id="vendor-notes"
                    :label="t('scampause.vendor_form.notes')"
                    :error="form.errors.notes"
                    optional
                    :optional-label="t('scampause.common.optional')"
                >
                    <Textarea
                        id="vendor-notes"
                        v-model="form.notes"
                        rows="4"
                        maxlength="4000"
                    />
                </FormField>
            </fieldset>

            <p class="text-sm text-pretty text-muted-foreground">
                {{ t('scampause.vendor_form.next_hint') }}
            </p>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button as-child variant="outline">
                    <Link
                        :href="
                            vendor
                                ? sp.vendors.show(vendor.id)
                                : sp.vendors.index()
                        "
                        >{{ t('scampause.common.cancel') }}</Link
                    >
                </Button>
                <Button
                    type="submit"
                    :disabled="blocked || form.processing"
                    :aria-busy="form.processing"
                    data-test="vendor-save"
                >
                    <Spinner v-if="form.processing" />
                    <Save v-else aria-hidden="true" />
                    {{ t('scampause.vendor_form.save') }}
                </Button>
            </div>
        </form>
    </div>
</template>
