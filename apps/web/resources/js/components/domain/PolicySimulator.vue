<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    FlaskConical,
    PhoneCall,
    Stamp,
    TimerReset,
    UsersRound,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
import ExhibitCard from '@/components/domain/ExhibitCard.vue';
import FormField from '@/components/domain/FormField.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { normalizeAmountInput, parseAmount } from '@/lib/domain/money';
import { sp } from '@/lib/domain/routes';
import { roleLabel } from '@/lib/domain/status';
import { t, tc } from '@/lib/i18n';
import type {
    Option,
    PaymentRequestKind,
    PolicyEvaluation,
    RiskLevel,
} from '@/types/domain';

const props = defineProps<{
    baseCurrency: string;
    currencies: string[];
    kinds: Option<PaymentRequestKind>[];
    riskLevels: Option<RiskLevel>[];
}>();

const amount = ref('5000');
const currency = ref(props.baseCurrency);
const kind = ref<PaymentRequestKind>('invoice');
const risk = ref<RiskLevel>('low');
const newVendor = ref(false);
const bankChange = ref(false);

const http = useHttp<
    {
        amount: string;
        currency: string;
        kind: PaymentRequestKind;
        risk_level: RiskLevel;
        new_vendor: boolean;
        bank_change: boolean;
    },
    PolicyEvaluation
>({
    amount: '',
    currency: props.baseCurrency,
    kind: 'invoice',
    risk_level: 'low',
    new_vendor: false,
    bank_change: false,
});

const result = ref<PolicyEvaluation | null>(null);
const failed = ref(false);

const amountValid = computed(() => {
    const minor = parseAmount(amount.value, currency.value);

    return minor !== null && minor > 0;
});

async function run(): Promise<void> {
    if (!amountValid.value) {
        return;
    }

    http.amount = normalizeAmountInput(amount.value);
    http.currency = currency.value;
    http.kind = kind.value;
    http.risk_level = risk.value;
    http.new_vendor = newVendor.value;
    http.bank_change = bankChange.value;

    try {
        const response = await http.post(sp.policies.simulate().url);
        failed.value = !response;
        result.value = response ?? result.value;
    } catch {
        failed.value = true;
    }
}

watchDebounced(
    [amount, currency, kind, risk, newVendor, bankChange],
    () => void run(),
    {
        debounce: 350,
    },
);

onMounted(() => void run());
</script>

<template>
    <ExhibitCard
        :eyebrow="t('scampause.policies.simulator_eyebrow')"
        :title="t('scampause.policies.simulator_title')"
        :description="t('scampause.policies.simulator_body')"
        :icon="FlaskConical"
        data-test="policy-simulator"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <div
                class="grid grid-cols-[minmax(0,1fr)_6rem] gap-2 sm:col-span-2"
            >
                <FormField
                    id="sim-amount"
                    :label="t('scampause.fields.amount')"
                    :error="
                        amountValid || !amount
                            ? (http.errors.amount ?? null)
                            : t('scampause.intake.amount_invalid')
                    "
                >
                    <Input
                        id="sim-amount"
                        v-model="amount"
                        inputmode="decimal"
                        class="text-right tabular-nums"
                        data-test="sim-amount"
                    />
                </FormField>
                <FormField
                    id="sim-currency"
                    :label="t('scampause.fields.currency')"
                >
                    <Select v-model="currency">
                        <SelectTrigger id="sim-currency" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="code in currencies"
                                :key="code"
                                :value="code"
                                >{{ code }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </FormField>
            </div>
            <FormField id="sim-kind" :label="t('scampause.fields.kind')">
                <Select v-model="kind">
                    <SelectTrigger id="sim-kind" class="w-full"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in kinds"
                            :key="option.value"
                            :value="option.value"
                            >{{ option.label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </FormField>
            <FormField id="sim-risk" :label="t('scampause.fields.risk')">
                <Select v-model="risk">
                    <SelectTrigger id="sim-risk" class="w-full"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in riskLevels"
                            :key="option.value"
                            :value="option.value"
                            >{{ option.label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </FormField>
            <Label
                for="sim-new"
                class="flex cursor-pointer items-center gap-3 font-normal"
            >
                <Switch id="sim-new" v-model="newVendor" />
                {{ t('scampause.policies.cond.new_vendor') }}
            </Label>
            <Label
                for="sim-bank"
                class="flex cursor-pointer items-center gap-3 font-normal"
            >
                <Switch id="sim-bank" v-model="bankChange" />
                {{ t('scampause.policies.cond.bank_change') }}
            </Label>
        </div>

        <div
            class="mt-5 rounded-lg border bg-muted/40 p-4"
            aria-live="polite"
            data-test="sim-result"
        >
            <p v-if="failed && !result" class="text-sm text-destructive">
                {{ t('scampause.policies.sim_failed') }}
            </p>
            <template v-else-if="result">
                <ul class="grid gap-3 sm:grid-cols-2">
                    <li class="flex items-start gap-2">
                        <Stamp
                            class="mt-0.5 size-4 text-primary"
                            aria-hidden="true"
                        />
                        <span>
                            <span
                                class="block font-semibold"
                                data-test="sim-approvers"
                                >{{
                                    tc(
                                        'scampause.policies.req.approvers',
                                        result.approvals_required,
                                    )
                                }}</span
                            >
                            <span
                                v-if="result.required_roles.length"
                                class="text-sm text-muted-foreground"
                                >{{
                                    t('scampause.policies.req.roles', {
                                        roles: result.required_roles
                                            .map((role) => roleLabel(role))
                                            .join(', '),
                                    })
                                }}</span
                            >
                        </span>
                    </li>
                    <li class="flex items-start gap-2">
                        <PhoneCall
                            :class="[
                                'mt-0.5 size-4',
                                result.verification_required
                                    ? 'text-hazard-ink'
                                    : 'text-muted-foreground',
                            ]"
                            aria-hidden="true"
                        />
                        <span class="text-sm">{{
                            result.verification_required
                                ? t('scampause.policies.req.verification')
                                : t('scampause.policies.sim_no_verification')
                        }}</span>
                    </li>
                    <li class="flex items-start gap-2 text-sm">
                        <TimerReset
                            class="mt-0.5 size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        {{
                            tc(
                                'scampause.policies.req.expiry',
                                result.expiry_hours,
                            )
                        }}
                    </li>
                    <li class="flex items-start gap-2 text-sm">
                        <UsersRound
                            class="mt-0.5 size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        {{
                            tc(
                                'scampause.route.eligible',
                                result.eligible_approvers,
                            )
                        }}
                    </li>
                </ul>
                <p
                    v-if="result.amount_base"
                    class="mt-3 text-xs text-muted-foreground"
                >
                    {{
                        t('scampause.policies.sim_base', {
                            amount: result.amount_base.formatted,
                        })
                    }}
                </p>
                <p class="mt-2 text-xs text-muted-foreground">
                    {{ t('scampause.policies.sim_matched') }}:
                    {{
                        result.matched_rules
                            .map((rule) => rule.name)
                            .join(', ') || t('domain.policy.fallback_rule')
                    }}
                </p>
                <ul
                    v-if="result.notes.length"
                    class="mt-2 space-y-1 text-xs text-hazard-ink"
                >
                    <li v-for="note in result.notes" :key="note">{{ note }}</li>
                </ul>
            </template>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('scampause.policies.sim_hint') }}
            </p>
        </div>
    </ExhibitCard>
</template>
