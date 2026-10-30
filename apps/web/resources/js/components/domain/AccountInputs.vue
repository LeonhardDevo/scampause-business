<script setup lang="ts">
import { CircleCheck, CircleX, Hourglass } from '@lucide/vue';
import { computed } from 'vue';
import FormField from '@/components/domain/FormField.vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    accountChecksum,
    groupByFour,
    routingChecksum,
    routingRequired,
} from '@/lib/domain/bank-validators';
import type { ChecksumState } from '@/lib/domain/bank-validators';
import { enumLabel } from '@/lib/domain/status';
import { t } from '@/lib/i18n';
import type { BankScheme } from '@/types/domain';

export type AccountDraft = {
    scheme: BankScheme;
    account_number: string;
    routing_code: string;
    bic: string;
    account_holder: string;
    bank_name: string;
    bank_country?: string;
    currency?: string;
};

const props = withDefaults(
    defineProps<{
        idPrefix: string;
        errors?: Record<string, string | undefined>;
        errorPrefix?: string;
        showHolder?: boolean;
        showBank?: boolean;
        showCountry?: boolean;
        currencies?: string[];
        accountOptional?: boolean;
    }>(),
    {
        errors: () => ({}),
        errorPrefix: '',
        showHolder: true,
        showBank: true,
        showCountry: false,
        currencies: () => [],
        accountOptional: false,
    },
);

const model = defineModel<AccountDraft>({ required: true });

const SCHEMES: BankScheme[] = [
    'iban',
    'us_ach',
    'ru_account',
    'uk_sort_code',
    'other',
];

const routingLabel = computed(() => {
    switch (model.value.scheme) {
        case 'us_ach':
            return t('scampause.account.routing_aba');
        case 'ru_account':
            return t('scampause.account.routing_bik');
        case 'uk_sort_code':
            return t('scampause.account.routing_sort');
        default:
            return t('scampause.account.routing');
    }
});

const accountState = computed(() =>
    accountChecksum(
        model.value.scheme,
        model.value.account_number,
        model.value.routing_code,
    ),
);
const routingState = computed(() =>
    routingChecksum(model.value.scheme, model.value.routing_code),
);

const STATE_META: Record<
    Exclude<ChecksumState, 'not_applicable'>,
    { icon: typeof CircleCheck; tone: string; key: string }
> = {
    ok: { icon: CircleCheck, tone: 'text-success', key: 'checksum_ok' },
    invalid: {
        icon: CircleX,
        tone: 'text-destructive',
        key: 'checksum_invalid',
    },
    incomplete: {
        icon: Hourglass,
        tone: 'text-muted-foreground',
        key: 'checksum_incomplete',
    },
};

function err(field: string): string | undefined {
    return props.errors[`${props.errorPrefix}${field}`];
}

function onAccountBlur(): void {
    if (model.value.scheme === 'iban') {
        model.value.account_number = groupByFour(model.value.account_number);
    }
}
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2" data-test="account-inputs">
        <FormField
            :id="`${idPrefix}-scheme`"
            :label="t('scampause.account.scheme')"
            :error="err('scheme')"
        >
            <Select v-model="model.scheme">
                <SelectTrigger :id="`${idPrefix}-scheme`" class="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="scheme in SCHEMES"
                        :key="scheme"
                        :value="scheme"
                        >{{ enumLabel('bank_scheme', scheme) }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </FormField>

        <FormField
            :id="`${idPrefix}-account`"
            :label="
                model.scheme === 'iban'
                    ? 'IBAN'
                    : t('scampause.account.account_number')
            "
            :error="err('account_number')"
            :optional="accountOptional"
            :optional-label="t('scampause.common.optional')"
            class="sm:col-span-2"
        >
            <template #default="{ describedby, invalid }">
                <Input
                    :id="`${idPrefix}-account`"
                    v-model="model.account_number"
                    class="font-mono tracking-[0.04em]"
                    autocomplete="off"
                    spellcheck="false"
                    inputmode="text"
                    maxlength="64"
                    :aria-describedby="
                        [describedby, `${idPrefix}-account-check`]
                            .filter(Boolean)
                            .join(' ')
                    "
                    :aria-invalid="
                        invalid || accountState === 'invalid' || undefined
                    "
                    data-test="account-number"
                    @blur="onAccountBlur"
                />
                <p
                    v-if="accountState !== 'not_applicable'"
                    :id="`${idPrefix}-account-check`"
                    :class="[
                        'flex items-center gap-1.5 text-xs font-medium',
                        STATE_META[accountState].tone,
                    ]"
                    aria-live="polite"
                    data-test="account-checksum"
                    :data-state="accountState"
                >
                    <component
                        :is="STATE_META[accountState].icon"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    {{ t(`scampause.account.${STATE_META[accountState].key}`) }}
                </p>
            </template>
        </FormField>

        <FormField
            v-if="routingRequired(model.scheme) || model.scheme === 'other'"
            :id="`${idPrefix}-routing`"
            :label="routingLabel"
            :error="err('routing_code')"
            :optional="!routingRequired(model.scheme)"
            :optional-label="t('scampause.common.optional')"
        >
            <template #default="{ describedby, invalid }">
                <Input
                    :id="`${idPrefix}-routing`"
                    v-model="model.routing_code"
                    class="font-mono"
                    autocomplete="off"
                    inputmode="numeric"
                    maxlength="16"
                    :aria-describedby="describedby"
                    :aria-invalid="
                        invalid || routingState === 'invalid' || undefined
                    "
                />
                <p
                    v-if="routingState !== 'not_applicable'"
                    :class="[
                        'flex items-center gap-1.5 text-xs font-medium',
                        STATE_META[routingState].tone,
                    ]"
                    aria-live="polite"
                >
                    <component
                        :is="STATE_META[routingState].icon"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    {{ t(`scampause.account.${STATE_META[routingState].key}`) }}
                </p>
            </template>
        </FormField>

        <FormField
            :id="`${idPrefix}-bic`"
            label="BIC / SWIFT"
            :error="err('bic')"
            optional
            :optional-label="t('scampause.common.optional')"
        >
            <Input
                :id="`${idPrefix}-bic`"
                v-model="model.bic"
                class="font-mono uppercase"
                autocomplete="off"
                maxlength="11"
            />
        </FormField>

        <FormField
            v-if="showHolder"
            :id="`${idPrefix}-holder`"
            :label="t('scampause.account.holder')"
            :error="err('account_holder')"
            optional
            :optional-label="t('scampause.common.optional')"
        >
            <Input
                :id="`${idPrefix}-holder`"
                v-model="model.account_holder"
                maxlength="160"
                autocomplete="off"
            />
        </FormField>

        <FormField
            v-if="showBank"
            :id="`${idPrefix}-bank`"
            :label="t('scampause.account.bank_name')"
            :error="err('bank_name')"
            optional
            :optional-label="t('scampause.common.optional')"
        >
            <Input
                :id="`${idPrefix}-bank`"
                v-model="model.bank_name"
                maxlength="160"
                autocomplete="off"
            />
        </FormField>

        <FormField
            v-if="showCountry"
            :id="`${idPrefix}-country`"
            :label="t('scampause.account.bank_country')"
            :error="err('bank_country')"
            optional
            :optional-label="t('scampause.common.optional')"
        >
            <Input
                :id="`${idPrefix}-country`"
                v-model="model.bank_country"
                class="font-mono uppercase"
                maxlength="2"
                autocomplete="off"
            />
        </FormField>

        <FormField
            v-if="currencies.length"
            :id="`${idPrefix}-currency`"
            :label="t('scampause.fields.currency')"
            :error="err('currency')"
            optional
            :optional-label="t('scampause.common.optional')"
        >
            <Select v-model="model.currency">
                <SelectTrigger :id="`${idPrefix}-currency`" class="w-full">
                    <SelectValue :placeholder="t('scampause.common.choose')" />
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
</template>
