<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Scale } from '@lucide/vue';
import { computed, watch } from 'vue';
import ChipToggleGroup from '@/components/common/ChipToggleGroup.vue';
import FormField from '@/components/domain/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { minorToInput, normalizeAmountInput } from '@/lib/domain/money';
import { sp } from '@/lib/domain/routes';
import { t } from '@/lib/i18n';
import type {
    Option,
    PaymentRequestKind,
    RiskLevel,
    Role,
    RuleDto,
} from '@/types/domain';

const props = defineProps<{
    rule: RuleDto | null;
    baseCurrency: string;
    options: {
        kinds: Option<PaymentRequestKind>[];
        roles: Option<Role>[];
        risk_levels: Option<RiskLevel>[];
    };
}>();

const open = defineModel<boolean>('open', { default: false });

type RuleForm = {
    name: string;
    description: string;
    is_active: boolean;
    min_amount: string;
    max_amount: string;
    kinds: string[];
    min_risk_level: string;
    applies_to_new_vendor: boolean;
    applies_to_bank_change: boolean;
    approvals_required: string;
    required_roles: string[];
    approver_roles: string[];
    verification_required: boolean;
    expiry_hours: string;
};

function initial(): RuleForm {
    const rule = props.rule;

    return {
        name: rule?.name ?? '',
        description: rule?.description ?? '',
        is_active: rule?.is_active ?? true,
        min_amount: rule?.min_amount
            ? minorToInput(rule.min_amount.minor, rule.min_amount.currency)
            : '',
        max_amount: rule?.max_amount
            ? minorToInput(rule.max_amount.minor, rule.max_amount.currency)
            : '',
        kinds: [...(rule?.kinds ?? [])],
        min_risk_level: rule?.min_risk_level ?? '',
        applies_to_new_vendor: rule?.applies_to_new_vendor === true,
        applies_to_bank_change: rule?.applies_to_bank_change === true,
        approvals_required: String(rule?.approvals_required ?? 1),
        required_roles: [...(rule?.required_roles ?? [])],
        approver_roles: [
            ...(rule?.approver_roles ?? ['owner', 'admin', 'approver']),
        ],
        verification_required: rule?.verification_required ?? false,
        expiry_hours: rule?.expiry_hours ? String(rule.expiry_hours) : '',
    };
}

const form = useForm<RuleForm>(initial());

watch(open, (value) => {
    if (value) {
        form.defaults(initial());
        form.reset();
        form.clearErrors();
    }
});

const roleError = computed(
    () => form.required_roles.length > Number(form.approvals_required),
);

const blocked = computed(
    () =>
        !form.name.trim() ||
        form.approver_roles.length === 0 ||
        roleError.value,
);

function submit(): void {
    if (blocked.value || form.processing) {
        return;
    }

    const request = form.transform((data) => ({
        name: data.name.trim(),
        description: data.description || null,
        is_active: data.is_active,
        min_amount: data.min_amount
            ? normalizeAmountInput(data.min_amount)
            : null,
        max_amount: data.max_amount
            ? normalizeAmountInput(data.max_amount)
            : null,
        kinds: data.kinds.length ? data.kinds : null,
        min_risk_level: data.min_risk_level || null,
        applies_to_new_vendor: data.applies_to_new_vendor ? true : null,
        applies_to_bank_change: data.applies_to_bank_change ? true : null,
        approvals_required: Number(data.approvals_required),
        required_roles: data.required_roles,
        approver_roles: data.approver_roles,
        verification_required: data.verification_required,
        expiry_hours: data.expiry_hours ? Number(data.expiry_hours) : null,
    }));
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.rule) {
        request.patch(sp.policies.updateRule(props.rule.id).url, options);
    } else {
        request.post(sp.policies.storeRule().url, options);
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-xl"
            data-test="rule-sheet"
        >
            <SheetHeader class="border-b px-5 py-4">
                <SheetTitle class="flex items-center gap-2">
                    <Scale class="size-4" aria-hidden="true" />
                    {{
                        rule
                            ? t('scampause.policies.edit_rule')
                            : t('scampause.policies.add_rule')
                    }}
                </SheetTitle>
                <SheetDescription>{{
                    t('scampause.policies.rule_sheet_body')
                }}</SheetDescription>
            </SheetHeader>
            <form
                id="rule-form"
                class="flex-1 space-y-6 px-5 py-5"
                @submit.prevent="submit"
            >
                <div class="space-y-4">
                    <FormField
                        id="rule-name"
                        :label="t('scampause.policies.rule_name')"
                        :error="form.errors.name"
                    >
                        <Input
                            id="rule-name"
                            v-model="form.name"
                            maxlength="120"
                            data-test="rule-name"
                        />
                    </FormField>
                    <FormField
                        id="rule-description"
                        :label="t('scampause.fields.description')"
                        :error="form.errors.description"
                        optional
                        :optional-label="t('scampause.common.optional')"
                    >
                        <Textarea
                            id="rule-description"
                            v-model="form.description"
                            rows="2"
                            maxlength="500"
                        />
                    </FormField>
                    <Label
                        for="rule-active"
                        class="flex cursor-pointer items-center gap-3 font-normal"
                    >
                        <Switch id="rule-active" v-model="form.is_active" />
                        {{ t('scampause.policies.active') }}
                    </Label>
                </div>

                <fieldset class="space-y-4">
                    <legend
                        class="eyebrow text-[0.6875rem] text-muted-foreground"
                    >
                        {{ t('scampause.policies.when') }}
                    </legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField
                            id="rule-min"
                            :label="
                                t('scampause.policies.min_amount', {
                                    currency: baseCurrency,
                                })
                            "
                            :error="form.errors.min_amount"
                            optional
                            :optional-label="t('scampause.common.optional')"
                        >
                            <Input
                                id="rule-min"
                                v-model="form.min_amount"
                                inputmode="decimal"
                                class="text-right tabular-nums"
                            />
                        </FormField>
                        <FormField
                            id="rule-max"
                            :label="
                                t('scampause.policies.max_amount', {
                                    currency: baseCurrency,
                                })
                            "
                            :error="form.errors.max_amount"
                            optional
                            :optional-label="t('scampause.common.optional')"
                        >
                            <Input
                                id="rule-max"
                                v-model="form.max_amount"
                                inputmode="decimal"
                                class="text-right tabular-nums"
                            />
                        </FormField>
                    </div>
                    <div class="space-y-1.5">
                        <p class="text-sm font-medium">
                            {{ t('scampause.policies.kinds') }}
                        </p>
                        <ChipToggleGroup
                            v-model="form.kinds"
                            :options="options.kinds"
                            :label="t('scampause.policies.kinds')"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ t('scampause.policies.kinds_hint') }}
                        </p>
                    </div>
                    <FormField
                        id="rule-risk"
                        :label="t('scampause.policies.min_risk')"
                        :error="form.errors.min_risk_level"
                        optional
                        :optional-label="t('scampause.common.optional')"
                    >
                        <Select v-model="form.min_risk_level">
                            <SelectTrigger id="rule-risk" class="w-full">
                                <SelectValue
                                    :placeholder="t('scampause.common.any')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="level in options.risk_levels"
                                    :key="level.value"
                                    :value="level.value"
                                    >{{ level.label }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </FormField>
                    <Label
                        for="rule-new-vendor"
                        class="flex cursor-pointer items-center gap-3 font-normal"
                    >
                        <Switch
                            id="rule-new-vendor"
                            v-model="form.applies_to_new_vendor"
                        />
                        {{ t('scampause.policies.cond.new_vendor') }}
                    </Label>
                    <Label
                        for="rule-bank-change"
                        class="flex cursor-pointer items-center gap-3 font-normal"
                    >
                        <Switch
                            id="rule-bank-change"
                            v-model="form.applies_to_bank_change"
                        />
                        {{ t('scampause.policies.cond.bank_change') }}
                    </Label>
                </fieldset>

                <fieldset class="space-y-4">
                    <legend
                        class="eyebrow text-[0.6875rem] text-muted-foreground"
                    >
                        {{ t('scampause.policies.then') }}
                    </legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField
                            id="rule-approvals"
                            :label="t('scampause.policies.approvals_required')"
                            :error="
                                form.errors.approvals_required ??
                                (roleError
                                    ? t('scampause.policies.roles_exceed')
                                    : null)
                            "
                        >
                            <Select v-model="form.approvals_required">
                                <SelectTrigger
                                    id="rule-approvals"
                                    class="w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="count in 5"
                                        :key="count"
                                        :value="String(count)"
                                        >{{ count }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </FormField>
                        <FormField
                            id="rule-expiry"
                            :label="t('scampause.policies.expiry_hours')"
                            :error="form.errors.expiry_hours"
                            optional
                            :optional-label="t('scampause.common.optional')"
                        >
                            <Input
                                id="rule-expiry"
                                v-model="form.expiry_hours"
                                type="number"
                                min="4"
                                max="168"
                            />
                        </FormField>
                    </div>
                    <div class="space-y-1.5">
                        <p class="text-sm font-medium">
                            {{ t('scampause.policies.required_roles') }}
                        </p>
                        <ChipToggleGroup
                            v-model="form.required_roles"
                            :options="options.roles"
                            :label="t('scampause.policies.required_roles')"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ t('scampause.policies.required_roles_hint') }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <p class="text-sm font-medium">
                            {{ t('scampause.policies.approver_roles') }}
                        </p>
                        <ChipToggleGroup
                            v-model="form.approver_roles"
                            :options="options.roles"
                            :label="t('scampause.policies.approver_roles')"
                        />
                        <p
                            v-if="
                                form.errors.approver_roles ||
                                !form.approver_roles.length
                            "
                            class="text-sm text-destructive"
                        >
                            {{
                                form.errors.approver_roles ??
                                t('scampause.policies.approver_roles_required')
                            }}
                        </p>
                    </div>
                    <Label
                        for="rule-verification"
                        class="flex cursor-pointer items-center gap-3 font-normal"
                    >
                        <Switch
                            id="rule-verification"
                            v-model="form.verification_required"
                        />
                        {{ t('scampause.policies.req.verification') }}
                    </Label>
                </fieldset>
            </form>
            <SheetFooter class="border-t px-5 py-4">
                <Button
                    type="submit"
                    form="rule-form"
                    :disabled="blocked || form.processing"
                    data-test="rule-save"
                >
                    <Spinner v-if="form.processing" />
                    {{ t('scampause.common.save') }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
