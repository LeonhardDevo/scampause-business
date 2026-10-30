<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowRight,
    ArrowUp,
    Coins,
    GripVertical,
    PencilLine,
    Plus,
    Scale,
    Settings2,
    Trash2,
    UsersRound,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import TagsInput from '@/components/common/TagsInput.vue';
import UsageMeter from '@/components/common/UsageMeter.vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import ExhibitCard from '@/components/domain/ExhibitCard.vue';
import FormField from '@/components/domain/FormField.vue';
import PolicySimulator from '@/components/domain/PolicySimulator.vue';
import RuleSheet from '@/components/domain/RuleSheet.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useFormat } from '@/composables/useFormat';
import { minorToInput, normalizeAmountInput } from '@/lib/domain/money';
import {
    moveItem,
    offsetsValid,
    parseOffsets,
    ruleConditions,
    ruleRequirements,
} from '@/lib/domain/policy';
import { sp } from '@/lib/domain/routes';
import { roleLabel } from '@/lib/domain/status';
import { activeLocale, t } from '@/lib/i18n';
import type {
    PoliciesProps,
    PolicyMember,
    PolicyTab,
    RuleDto,
} from '@/types/domain';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'scampause.nav.policies', href: sp.policies.index() },
        ],
    },
});

const props = defineProps<PoliciesProps>();

const fmt = useFormat();
const tab = ref<PolicyTab>(props.tab);

watch(tab, (value) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', value);
    window.history.replaceState(window.history.state, '', url.toString());
});

const rulesLimitReached = computed(
    () =>
        props.limits.rules.limit !== null &&
        props.limits.rules.used >= props.limits.rules.limit,
);

const ordered = ref<RuleDto[]>([...props.rules]);
watch(
    () => props.rules,
    (value) => {
        ordered.value = [...value];
    },
);

const sheetOpen = ref(false);
const editing = ref<RuleDto | null>(null);

function openRule(rule: RuleDto | null): void {
    editing.value = rule;
    sheetOpen.value = true;
}

function rulePayload(
    rule: RuleDto,
    overrides: Partial<Record<string, FormDataConvertible>> = {},
): Record<string, FormDataConvertible> {
    return {
        name: rule.name,
        description: rule.description,
        is_active: rule.is_active,
        min_amount: rule.min_amount
            ? minorToInput(rule.min_amount.minor, rule.min_amount.currency)
            : null,
        max_amount: rule.max_amount
            ? minorToInput(rule.max_amount.minor, rule.max_amount.currency)
            : null,
        kinds: rule.kinds,
        min_risk_level: rule.min_risk_level,
        applies_to_new_vendor: rule.applies_to_new_vendor,
        applies_to_bank_change: rule.applies_to_bank_change,
        approvals_required: rule.approvals_required,
        required_roles: rule.required_roles,
        approver_roles: rule.approver_roles,
        verification_required: rule.verification_required,
        expiry_hours: rule.expiry_hours,
        ...overrides,
    };
}

const toggling = ref<number | null>(null);

function toggleActive(rule: RuleDto, value: boolean): void {
    toggling.value = rule.id;
    router.patch(
        sp.policies.updateRule(rule.id).url,
        rulePayload(rule, { is_active: value }),
        {
            preserveScroll: true,
            onFinish: () => {
                toggling.value = null;
            },
        },
    );
}

function saveOrder(): void {
    router.post(
        sp.policies.reorder().url,
        { ids: ordered.value.map((rule) => rule.id) },
        { preserveScroll: true },
    );
}

function move(index: number, delta: number): void {
    const next = moveItem(ordered.value, index, index + delta);

    if (next.some((rule, i) => rule.id !== ordered.value[i]?.id)) {
        ordered.value = next;
        saveOrder();
    }
}

const dragIndex = ref<number | null>(null);

function onDrop(index: number): void {
    if (dragIndex.value === null || dragIndex.value === index) {
        dragIndex.value = null;

        return;
    }

    ordered.value = moveItem(ordered.value, dragIndex.value, index);
    dragIndex.value = null;
    saveOrder();
}

const deleteOpen = ref(false);
const deleting = ref<RuleDto | null>(null);

function askDelete(rule: RuleDto): void {
    deleting.value = rule;
    deleteOpen.value = true;
}

function destroyRule(): void {
    if (deleting.value) {
        router.delete(sp.policies.destroyRule(deleting.value.id).url, {
            preserveScroll: true,
            onFinish: () => {
                deleteOpen.value = false;
            },
        });
    }
}

const limitDrafts = ref<Record<number, { approval: string; monthly: string }>>(
    {},
);

function initLimits(): void {
    limitDrafts.value = Object.fromEntries(
        props.members.map((member) => [
            member.id,
            {
                approval: member.approval_limit
                    ? minorToInput(
                          member.approval_limit.minor,
                          member.approval_limit.currency,
                      )
                    : '',
                monthly: member.monthly_limit
                    ? minorToInput(
                          member.monthly_limit.minor,
                          member.monthly_limit.currency,
                      )
                    : '',
            },
        ]),
    );
}

initLimits();
watch(() => props.members, initLimits);

const limitBusy = ref<number | null>(null);
const limitErrors = ref<Record<number, string | null>>({});

function saveLimit(member: PolicyMember): void {
    const draft = limitDrafts.value[member.id];
    limitBusy.value = member.id;
    limitErrors.value = { ...limitErrors.value, [member.id]: null };
    router.put(
        sp.policies.limits(member.id).url,
        {
            approval_limit: draft.approval
                ? normalizeAmountInput(draft.approval)
                : null,
            monthly_limit: draft.monthly
                ? normalizeAmountInput(draft.monthly)
                : null,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                limitErrors.value = {
                    ...limitErrors.value,
                    [member.id]: Object.values(errors)[0] ?? null,
                };
            },
            onFinish: () => {
                limitBusy.value = null;
            },
        },
    );
}

const DAYS = [1, 2, 3, 4, 5, 6, 7];

function dayLabel(day: number): string {
    const date = new Date(Date.UTC(2024, 0, day));

    return new Intl.DateTimeFormat(
        activeLocale() === 'ru' ? 'ru-RU' : 'en-US',
        {
            weekday: 'short',
            timeZone: 'UTC',
        },
    ).format(date);
}

const settingsForm = useForm({
    own_domains: [...props.settings.own_domains],
    business_days: [...props.settings.business_days],
    business_hours_start: props.settings.business_hours_start,
    business_hours_end: props.settings.business_hours_end,
    quiet_hours_start: props.settings.quiet_hours_start,
    quiet_hours_end: props.settings.quiet_hours_end,
    default_expiry_hours: String(props.settings.default_expiry_hours),
    reminder_offsets: props.settings.reminder_offsets_hours.join(', '),
    escalate_after_hours: String(props.settings.escalate_after_hours),
    telegram_approvals_enabled: props.settings.telegram_approvals_enabled,
    telegram_approve_max_risk:
        props.settings.telegram_approve_max_risk === 'critical'
            ? 'high'
            : props.settings.telegram_approve_max_risk,
    verification_amount_threshold:
        props.settings.verification_amount_threshold_input ??
        minorToInput(
            props.settings.verification_amount_threshold.minor,
            props.settings.verification_amount_threshold.currency,
        ),
    verification_valid_days: String(props.settings.verification_valid_days),
});

function toggleDay(day: number): void {
    settingsForm.business_days = settingsForm.business_days.includes(day)
        ? settingsForm.business_days.filter((item) => item !== day)
        : [...settingsForm.business_days, day].sort();
}

const offsets = computed(() => parseOffsets(settingsForm.reminder_offsets));
const offsetsError = computed(() => {
    if (
        offsets.value === null ||
        !offsetsValid(offsets.value, Number(settingsForm.default_expiry_hours))
    ) {
        return t('scampause.policies.settings.offsets_invalid');
    }

    return null;
});
const hoursError = computed(() =>
    settingsForm.business_hours_end <= settingsForm.business_hours_start
        ? t('scampause.policies.settings.hours_invalid')
        : null,
);

const settingsErrors = computed(
    () => settingsForm.errors as Record<string, string | undefined>,
);

function saveSettings(): void {
    if (
        offsetsError.value ||
        hoursError.value ||
        !settingsForm.business_days.length ||
        settingsForm.processing
    ) {
        return;
    }

    settingsForm
        .transform((data) => ({
            own_domains: data.own_domains,
            business_days: data.business_days,
            business_hours_start: data.business_hours_start,
            business_hours_end: data.business_hours_end,
            quiet_hours_start: data.quiet_hours_start,
            quiet_hours_end: data.quiet_hours_end,
            default_expiry_hours: Number(data.default_expiry_hours),
            reminder_offsets_hours: offsets.value ?? [],
            escalate_after_hours: Number(data.escalate_after_hours),
            telegram_approvals_enabled: data.telegram_approvals_enabled,
            telegram_approve_max_risk: data.telegram_approve_max_risk,
            verification_amount_threshold: normalizeAmountInput(
                data.verification_amount_threshold,
            ),
            verification_valid_days: Number(data.verification_valid_days),
        }))
        .put(sp.policies.settings().url, { preserveScroll: true });
}

const rateForm = useForm({ quote_currency: '', rate: '', effective_date: '' });

onMounted(() => {
    const now = new Date();
    rateForm.effective_date = new Date(
        now.getTime() - now.getTimezoneOffset() * 60_000,
    )
        .toISOString()
        .slice(0, 10);
});

const otherCurrencies = computed(() =>
    props.currencies.filter((code) => code !== props.base_currency),
);

function addRate(): void {
    if (
        !rateForm.quote_currency ||
        !rateForm.rate.trim() ||
        rateForm.processing
    ) {
        return;
    }

    rateForm
        .transform((data) => ({
            ...data,
            rate: normalizeAmountInput(data.rate),
        }))
        .post(sp.policies.storeRate().url, {
            preserveScroll: true,
            onSuccess: () => rateForm.reset('rate'),
        });
}

function removeRate(id: number): void {
    router.delete(sp.policies.destroyRate(id).url, { preserveScroll: true });
}

const RISK_OPTIONS = ['low', 'medium', 'high'] as const;
</script>

<template>
    <Head :title="t('scampause.policies.title')" />

    <div
        class="mx-auto flex w-full max-w-[90rem] flex-col gap-5 px-4 py-6 sm:px-6 lg:py-8"
        data-test="policies-page"
    >
        <PageHeader
            :icon="Scale"
            :eyebrow="t('scampause.policies.eyebrow')"
            :title="t('scampause.policies.title')"
            :description="t('scampause.policies.description')"
        />

        <Tabs v-model="tab" class="gap-5">
            <div class="-mx-1 overflow-x-auto px-1">
                <TabsList class="inline-flex w-max">
                    <TabsTrigger value="rules" data-test="policies-tab-rules"
                        ><Scale aria-hidden="true" />{{
                            t('scampause.policies.tabs.rules')
                        }}</TabsTrigger
                    >
                    <TabsTrigger value="limits" data-test="policies-tab-limits"
                        ><UsersRound aria-hidden="true" />{{
                            t('scampause.policies.tabs.limits')
                        }}</TabsTrigger
                    >
                    <TabsTrigger
                        value="settings"
                        data-test="policies-tab-settings"
                        ><Settings2 aria-hidden="true" />{{
                            t('scampause.policies.tabs.settings')
                        }}</TabsTrigger
                    >
                    <TabsTrigger
                        value="currencies"
                        data-test="policies-tab-currencies"
                        ><Coins aria-hidden="true" />{{
                            t('scampause.policies.tabs.currencies')
                        }}</TabsTrigger
                    >
                </TabsList>
            </div>

            <TabsContent value="rules">
                <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_26rem]">
                    <div class="min-w-0 space-y-4">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <UsageMeter
                                v-if="limits.rules.limit !== null"
                                class="min-w-56 flex-1 sm:max-w-sm"
                                :label="t('scampause.policies.rules_usage')"
                                :entry="{
                                    used: limits.rules.used,
                                    limit: limits.rules.limit,
                                }"
                            />
                            <Button
                                v-if="abilities.manage"
                                :disabled="rulesLimitReached"
                                data-test="add-rule"
                                @click="openRule(null)"
                            >
                                <Plus aria-hidden="true" />
                                {{ t('scampause.policies.add_rule') }}
                            </Button>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ t('scampause.policies.order_hint') }}
                        </p>

                        <div
                            v-if="!ordered.length"
                            class="rounded-xl border bg-card"
                            data-test="rules-empty"
                        >
                            <EmptyState
                                :icon="Scale"
                                tone="muted"
                                :title="t('scampause.policies.empty_title')"
                                :description="
                                    t('scampause.policies.empty_body')
                                "
                            />
                        </div>
                        <ol v-else class="space-y-3">
                            <li
                                v-for="(rule, index) in ordered"
                                :key="rule.id"
                                :class="[
                                    'rounded-xl border bg-card p-4 shadow-xs transition-colors',
                                    rule.is_active ? '' : 'opacity-70',
                                    dragIndex === index ? 'border-ring' : '',
                                ]"
                                :draggable="abilities.manage"
                                data-test="rule-card"
                                @dragstart="dragIndex = index"
                                @dragover.prevent
                                @drop.prevent="onDrop(index)"
                            >
                                <div class="flex items-start gap-3">
                                    <div
                                        v-if="abilities.manage"
                                        class="flex flex-col items-center gap-0.5"
                                    >
                                        <GripVertical
                                            class="size-4 cursor-grab text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            :disabled="index === 0"
                                            :aria-label="
                                                t(
                                                    'scampause.policies.move_up',
                                                    { name: rule.name },
                                                )
                                            "
                                            @click="move(index, -1)"
                                        >
                                            <ArrowUp aria-hidden="true" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            :disabled="
                                                index === ordered.length - 1
                                            "
                                            :aria-label="
                                                t(
                                                    'scampause.policies.move_down',
                                                    { name: rule.name },
                                                )
                                            "
                                            @click="move(index, 1)"
                                        >
                                            <ArrowDown aria-hidden="true" />
                                        </Button>
                                    </div>
                                    <div class="min-w-0 flex-1 space-y-3">
                                        <div
                                            class="flex flex-wrap items-start justify-between gap-2"
                                        >
                                            <div class="min-w-0">
                                                <p
                                                    class="flex items-center gap-2 font-semibold"
                                                >
                                                    <span
                                                        class="font-mono text-xs text-muted-foreground"
                                                        >{{
                                                            String(
                                                                index + 1,
                                                            ).padStart(2, '0')
                                                        }}</span
                                                    >
                                                    {{ rule.name }}
                                                    <span
                                                        class="font-mono text-[0.6875rem] font-normal text-muted-foreground"
                                                        >v{{
                                                            rule.version
                                                        }}</span
                                                    >
                                                </p>
                                                <p
                                                    v-if="rule.description"
                                                    class="text-sm text-pretty text-muted-foreground"
                                                >
                                                    {{ rule.description }}
                                                </p>
                                            </div>
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                <Label
                                                    :for="`rule-active-${rule.id}`"
                                                    class="flex items-center gap-2 text-xs font-normal text-muted-foreground"
                                                >
                                                    <Switch
                                                        :id="`rule-active-${rule.id}`"
                                                        :model-value="
                                                            rule.is_active
                                                        "
                                                        :disabled="
                                                            !abilities.manage ||
                                                            toggling === rule.id
                                                        "
                                                        @update:model-value="
                                                            (value: boolean) =>
                                                                toggleActive(
                                                                    rule,
                                                                    value,
                                                                )
                                                        "
                                                    />
                                                    {{
                                                        rule.is_active
                                                            ? t(
                                                                  'scampause.policies.active',
                                                              )
                                                            : t(
                                                                  'scampause.policies.inactive',
                                                              )
                                                    }}
                                                </Label>
                                                <template
                                                    v-if="abilities.manage"
                                                >
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        :aria-label="
                                                            t(
                                                                'scampause.common.edit',
                                                            )
                                                        "
                                                        @click="openRule(rule)"
                                                    >
                                                        <PencilLine
                                                            aria-hidden="true"
                                                        />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        :aria-label="
                                                            t(
                                                                'scampause.common.delete',
                                                            )
                                                        "
                                                        @click="askDelete(rule)"
                                                    >
                                                        <Trash2
                                                            aria-hidden="true"
                                                        />
                                                    </Button>
                                                </template>
                                            </div>
                                        </div>
                                        <div
                                            class="flex flex-wrap items-center gap-1.5 text-sm"
                                            data-test="rule-sentence"
                                        >
                                            <span
                                                class="eyebrow text-[0.6875rem] text-muted-foreground"
                                                >{{
                                                    t('scampause.policies.if')
                                                }}</span
                                            >
                                            <span
                                                v-for="chip in ruleConditions(
                                                    rule,
                                                )"
                                                :key="chip"
                                                class="inline-flex min-h-6 items-center rounded-sm border bg-muted px-2 text-xs"
                                                >{{ chip }}</span
                                            >
                                            <ArrowRight
                                                class="size-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            <span
                                                v-for="chip in ruleRequirements(
                                                    rule,
                                                )"
                                                :key="chip"
                                                class="inline-flex min-h-6 items-center rounded-sm border border-info/30 bg-info-soft px-2 text-xs text-info-soft-foreground"
                                                >{{ chip }}</span
                                            >
                                        </div>
                                        <p
                                            v-if="rule.updated_by"
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                t(
                                                    'scampause.policies.updated',
                                                    {
                                                        name: rule.updated_by
                                                            .name,
                                                        date: fmt.date(
                                                            rule.updated_at,
                                                        ),
                                                    },
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </li>
                        </ol>
                    </div>
                    <div class="xl:sticky xl:top-20 xl:self-start">
                        <PolicySimulator
                            :base-currency="base_currency"
                            :currencies="currencies"
                            :kinds="options.kinds"
                            :risk-levels="options.risk_levels"
                        />
                    </div>
                </div>
            </TabsContent>

            <TabsContent value="limits">
                <ExhibitCard
                    :eyebrow="t('scampause.policies.limits_eyebrow')"
                    :title="t('scampause.policies.limits_title')"
                    :description="
                        t('scampause.policies.limits_body', {
                            currency: base_currency,
                        })
                    "
                    flush
                >
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[44rem] text-sm">
                            <caption class="sr-only">
                                {{
                                    t('scampause.policies.limits_title')
                                }}
                            </caption>
                            <thead
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th
                                        scope="col"
                                        class="px-4 py-2.5 font-medium"
                                    >
                                        {{ t('scampause.policies.member') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-2.5 font-medium"
                                    >
                                        {{
                                            t(
                                                'scampause.policies.approval_limit',
                                            )
                                        }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-2.5 font-medium"
                                    >
                                        {{
                                            t(
                                                'scampause.policies.monthly_limit',
                                            )
                                        }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-2.5 text-right font-medium"
                                    >
                                        {{
                                            t(
                                                'scampause.policies.approved_month',
                                            )
                                        }}
                                    </th>
                                    <th scope="col" class="px-4 py-2.5">
                                        <span class="sr-only">{{
                                            t('scampause.common.actions')
                                        }}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="member in members"
                                    :key="member.id"
                                    data-test="limit-row"
                                >
                                    <td class="px-4 py-3">
                                        <span class="flex items-center gap-2">
                                            <UserAvatar
                                                :name="member.name"
                                                :src="member.avatar"
                                                size="sm"
                                            />
                                            <span>
                                                <span
                                                    class="block font-medium"
                                                    >{{ member.name }}</span
                                                >
                                                <span
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {{ roleLabel(member.role) }}
                                                    <template
                                                        v-if="
                                                            !member.can_approve
                                                        "
                                                    >
                                                        ·
                                                        {{
                                                            t(
                                                                'scampause.policies.cannot_approve',
                                                            )
                                                        }}</template
                                                    >
                                                </span>
                                            </span>
                                        </span>
                                    </td>
                                    <template
                                        v-if="
                                            member.can_approve &&
                                            limitDrafts[member.id]
                                        "
                                    >
                                        <td class="px-4 py-3">
                                            <Input
                                                v-model="
                                                    limitDrafts[member.id]
                                                        .approval
                                                "
                                                inputmode="decimal"
                                                class="w-36 text-right tabular-nums"
                                                :disabled="!abilities.manage"
                                                :placeholder="
                                                    t(
                                                        'scampause.policies.no_limit',
                                                    )
                                                "
                                                :aria-label="
                                                    t(
                                                        'scampause.policies.approval_limit_for',
                                                        { name: member.name },
                                                    )
                                                "
                                            />
                                        </td>
                                        <td class="px-4 py-3">
                                            <Input
                                                v-model="
                                                    limitDrafts[member.id]
                                                        .monthly
                                                "
                                                inputmode="decimal"
                                                class="w-36 text-right tabular-nums"
                                                :disabled="!abilities.manage"
                                                :placeholder="
                                                    t(
                                                        'scampause.policies.no_limit',
                                                    )
                                                "
                                                :aria-label="
                                                    t(
                                                        'scampause.policies.monthly_limit_for',
                                                        { name: member.name },
                                                    )
                                                "
                                            />
                                            <p
                                                v-if="limitErrors[member.id]"
                                                class="mt-1 text-xs text-destructive"
                                                role="alert"
                                            >
                                                {{ limitErrors[member.id] }}
                                            </p>
                                        </td>
                                    </template>
                                    <td
                                        v-else
                                        colspan="2"
                                        class="px-4 py-3 text-muted-foreground"
                                    >
                                        —
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{
                                            member.approved_this_month.formatted
                                        }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <Button
                                            v-if="
                                                abilities.manage &&
                                                member.can_approve
                                            "
                                            size="sm"
                                            variant="outline"
                                            :disabled="limitBusy === member.id"
                                            @click="saveLimit(member)"
                                        >
                                            <Spinner
                                                v-if="limitBusy === member.id"
                                            />
                                            {{ t('scampause.common.save') }}
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </ExhibitCard>
            </TabsContent>

            <TabsContent value="settings">
                <form
                    class="grid gap-6 lg:grid-cols-2"
                    data-test="policy-settings"
                    @submit.prevent="saveSettings"
                >
                    <ExhibitCard
                        :eyebrow="
                            t('scampause.policies.settings.hours_eyebrow')
                        "
                        :title="t('scampause.policies.settings.hours_title')"
                    >
                        <div class="space-y-4">
                            <fieldset class="space-y-1.5">
                                <legend class="text-sm font-medium">
                                    {{
                                        t(
                                            'scampause.policies.settings.business_days',
                                        )
                                    }}
                                </legend>
                                <div class="flex flex-wrap gap-1.5">
                                    <button
                                        v-for="day in DAYS"
                                        :key="day"
                                        type="button"
                                        :aria-pressed="
                                            settingsForm.business_days.includes(
                                                day,
                                            )
                                        "
                                        :disabled="!abilities.manage"
                                        :class="[
                                            'h-9 min-w-11 rounded-md border px-2 text-sm capitalize focus-visible:outline-2 focus-visible:outline-ring',
                                            settingsForm.business_days.includes(
                                                day,
                                            )
                                                ? 'border-ring bg-accent font-medium'
                                                : 'bg-card text-muted-foreground',
                                        ]"
                                        @click="toggleDay(day)"
                                    >
                                        {{ dayLabel(day) }}
                                    </button>
                                </div>
                                <p
                                    v-if="
                                        !settingsForm.business_days.length ||
                                        settingsErrors.business_days
                                    "
                                    class="text-sm text-destructive"
                                >
                                    {{
                                        settingsErrors.business_days ??
                                        t(
                                            'scampause.policies.settings.days_required',
                                        )
                                    }}
                                </p>
                            </fieldset>
                            <div class="grid grid-cols-2 gap-3">
                                <FormField
                                    id="bh-start"
                                    :label="
                                        t(
                                            'scampause.policies.settings.business_start',
                                        )
                                    "
                                    :error="settingsErrors.business_hours_start"
                                >
                                    <Input
                                        id="bh-start"
                                        v-model="
                                            settingsForm.business_hours_start
                                        "
                                        type="time"
                                        :disabled="!abilities.manage"
                                    />
                                </FormField>
                                <FormField
                                    id="bh-end"
                                    :label="
                                        t(
                                            'scampause.policies.settings.business_end',
                                        )
                                    "
                                    :error="
                                        settingsErrors.business_hours_end ??
                                        hoursError
                                    "
                                >
                                    <Input
                                        id="bh-end"
                                        v-model="
                                            settingsForm.business_hours_end
                                        "
                                        type="time"
                                        :disabled="!abilities.manage"
                                    />
                                </FormField>
                                <FormField
                                    id="qh-start"
                                    :label="
                                        t(
                                            'scampause.policies.settings.quiet_start',
                                        )
                                    "
                                    :error="settingsErrors.quiet_hours_start"
                                >
                                    <Input
                                        id="qh-start"
                                        v-model="settingsForm.quiet_hours_start"
                                        type="time"
                                        :disabled="!abilities.manage"
                                    />
                                </FormField>
                                <FormField
                                    id="qh-end"
                                    :label="
                                        t(
                                            'scampause.policies.settings.quiet_end',
                                        )
                                    "
                                    :error="settingsErrors.quiet_hours_end"
                                >
                                    <Input
                                        id="qh-end"
                                        v-model="settingsForm.quiet_hours_end"
                                        type="time"
                                        :disabled="!abilities.manage"
                                    />
                                </FormField>
                            </div>
                        </div>
                    </ExhibitCard>

                    <ExhibitCard
                        :eyebrow="
                            t('scampause.policies.settings.deadlines_eyebrow')
                        "
                        :title="
                            t('scampause.policies.settings.deadlines_title')
                        "
                    >
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField
                                id="expiry"
                                :label="
                                    t(
                                        'scampause.policies.settings.default_expiry',
                                    )
                                "
                                :error="settingsErrors.default_expiry_hours"
                            >
                                <Input
                                    id="expiry"
                                    v-model="settingsForm.default_expiry_hours"
                                    type="number"
                                    min="4"
                                    max="168"
                                    :disabled="!abilities.manage"
                                />
                            </FormField>
                            <FormField
                                id="escalate"
                                :label="
                                    t(
                                        'scampause.policies.settings.escalate_after',
                                    )
                                "
                                :error="settingsErrors.escalate_after_hours"
                            >
                                <Input
                                    id="escalate"
                                    v-model="settingsForm.escalate_after_hours"
                                    type="number"
                                    min="1"
                                    max="168"
                                    :disabled="!abilities.manage"
                                />
                            </FormField>
                            <FormField
                                id="offsets"
                                :label="
                                    t('scampause.policies.settings.reminders')
                                "
                                :hint="
                                    t(
                                        'scampause.policies.settings.reminders_hint',
                                    )
                                "
                                :error="
                                    settingsErrors.reminder_offsets_hours ??
                                    offsetsError
                                "
                                class="sm:col-span-2"
                            >
                                <Input
                                    id="offsets"
                                    v-model="settingsForm.reminder_offsets"
                                    class="font-mono"
                                    :disabled="!abilities.manage"
                                />
                            </FormField>
                        </div>
                    </ExhibitCard>

                    <ExhibitCard
                        :eyebrow="
                            t(
                                'scampause.policies.settings.verification_eyebrow',
                            )
                        "
                        :title="
                            t('scampause.policies.settings.verification_title')
                        "
                    >
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField
                                id="threshold"
                                :label="
                                    t('scampause.policies.settings.threshold', {
                                        currency: base_currency,
                                    })
                                "
                                :hint="
                                    t(
                                        'scampause.policies.settings.threshold_hint',
                                    )
                                "
                                :error="
                                    settingsErrors.verification_amount_threshold
                                "
                            >
                                <Input
                                    id="threshold"
                                    v-model="
                                        settingsForm.verification_amount_threshold
                                    "
                                    inputmode="decimal"
                                    class="text-right tabular-nums"
                                    :disabled="!abilities.manage"
                                />
                            </FormField>
                            <FormField
                                id="valid-days"
                                :label="
                                    t('scampause.policies.settings.valid_days')
                                "
                                :hint="
                                    t(
                                        'scampause.policies.settings.valid_days_hint',
                                    )
                                "
                                :error="settingsErrors.verification_valid_days"
                            >
                                <Input
                                    id="valid-days"
                                    v-model="
                                        settingsForm.verification_valid_days
                                    "
                                    type="number"
                                    min="1"
                                    max="30"
                                    :disabled="!abilities.manage"
                                />
                            </FormField>
                        </div>
                    </ExhibitCard>

                    <ExhibitCard
                        :eyebrow="
                            t('scampause.policies.settings.channels_eyebrow')
                        "
                        :title="t('scampause.policies.settings.channels_title')"
                    >
                        <div class="space-y-4">
                            <Label
                                for="tg-enabled"
                                class="flex cursor-pointer items-start gap-3 font-normal"
                            >
                                <Switch
                                    id="tg-enabled"
                                    v-model="
                                        settingsForm.telegram_approvals_enabled
                                    "
                                    :disabled="
                                        !abilities.manage ||
                                        !limits.telegram_approvals
                                    "
                                />
                                <span>
                                    <span class="block font-medium">{{
                                        t(
                                            'scampause.policies.settings.telegram',
                                        )
                                    }}</span>
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            limits.telegram_approvals
                                                ? t(
                                                      'scampause.policies.settings.telegram_hint',
                                                  )
                                                : t(
                                                      'scampause.policies.settings.telegram_plan',
                                                  )
                                        }}</span
                                    >
                                </span>
                            </Label>
                            <FormField
                                id="tg-max"
                                :label="
                                    t(
                                        'scampause.policies.settings.telegram_max',
                                    )
                                "
                                :error="
                                    settingsErrors.telegram_approve_max_risk
                                "
                            >
                                <Select
                                    v-model="
                                        settingsForm.telegram_approve_max_risk
                                    "
                                    :disabled="
                                        !abilities.manage ||
                                        !settingsForm.telegram_approvals_enabled
                                    "
                                >
                                    <SelectTrigger id="tg-max" class="w-full"
                                        ><SelectValue
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="level in RISK_OPTIONS"
                                            :key="level"
                                            :value="level"
                                            >{{
                                                t(`ui.risk.levels.${level}`)
                                            }}</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                id="own-domains"
                                :label="
                                    t('scampause.policies.settings.own_domains')
                                "
                                :hint="
                                    t(
                                        'scampause.policies.settings.own_domains_hint',
                                    )
                                "
                                :error="settingsErrors.own_domains"
                            >
                                <TagsInput
                                    id="own-domains"
                                    v-model="settingsForm.own_domains"
                                    :max="10"
                                    :max-length="253"
                                />
                            </FormField>
                        </div>
                    </ExhibitCard>

                    <div
                        v-if="abilities.manage"
                        class="flex justify-end lg:col-span-2"
                    >
                        <Button
                            type="submit"
                            :disabled="
                                settingsForm.processing ||
                                Boolean(offsetsError) ||
                                Boolean(hoursError) ||
                                !settingsForm.business_days.length
                            "
                            data-test="settings-save"
                        >
                            <Spinner v-if="settingsForm.processing" />
                            {{ t('scampause.common.save') }}
                        </Button>
                    </div>
                </form>
            </TabsContent>

            <TabsContent value="currencies">
                <ExhibitCard
                    :eyebrow="t('scampause.policies.rates_eyebrow')"
                    :title="t('scampause.policies.rates_title')"
                    :description="
                        t('scampause.policies.rates_body', {
                            base: base_currency,
                        })
                    "
                >
                    <form
                        v-if="abilities.manage"
                        class="mb-5 grid gap-3 sm:grid-cols-[8rem_minmax(0,1fr)_10rem_auto] sm:items-end"
                        data-test="rate-form"
                        @submit.prevent="addRate"
                    >
                        <FormField
                            id="rate-currency"
                            :label="t('scampause.fields.currency')"
                            :error="rateForm.errors.quote_currency"
                        >
                            <Select v-model="rateForm.quote_currency">
                                <SelectTrigger id="rate-currency" class="w-full"
                                    ><SelectValue
                                        :placeholder="
                                            t('scampause.common.choose')
                                        "
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="code in otherCurrencies"
                                        :key="code"
                                        :value="code"
                                        >{{ code }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </FormField>
                        <FormField
                            id="rate-value"
                            :label="
                                t('scampause.policies.rate_label', {
                                    base: base_currency,
                                })
                            "
                            :error="rateForm.errors.rate"
                        >
                            <Input
                                id="rate-value"
                                v-model="rateForm.rate"
                                inputmode="decimal"
                                class="font-mono tabular-nums"
                            />
                        </FormField>
                        <FormField
                            id="rate-date"
                            :label="t('scampause.policies.effective_date')"
                            :error="rateForm.errors.effective_date"
                        >
                            <Input
                                id="rate-date"
                                v-model="rateForm.effective_date"
                                type="date"
                            />
                        </FormField>
                        <Button
                            type="submit"
                            :disabled="
                                !rateForm.quote_currency ||
                                !rateForm.rate.trim() ||
                                rateForm.processing
                            "
                        >
                            <Spinner v-if="rateForm.processing" />
                            <Plus v-else aria-hidden="true" />
                            {{ t('scampause.policies.add_rate') }}
                        </Button>
                    </form>
                    <EmptyState
                        v-if="!exchange_rates.length"
                        :icon="Coins"
                        tone="muted"
                        compact
                        :title="t('scampause.policies.rates_empty')"
                    />
                    <div v-else class="overflow-x-auto rounded-lg border">
                        <table class="w-full text-sm">
                            <caption class="sr-only">
                                {{
                                    t('scampause.policies.rates_title')
                                }}
                            </caption>
                            <thead
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 font-medium"
                                    >
                                        {{ t('scampause.fields.currency') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 text-right font-medium"
                                    >
                                        {{ t('scampause.policies.rate') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 font-medium"
                                    >
                                        {{
                                            t(
                                                'scampause.policies.effective_date',
                                            )
                                        }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 font-medium"
                                    >
                                        {{ t('scampause.policies.source') }}
                                    </th>
                                    <th scope="col" class="px-3 py-2">
                                        <span class="sr-only">{{
                                            t('scampause.common.actions')
                                        }}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="rate in exchange_rates"
                                    :key="rate.id"
                                >
                                    <td class="px-3 py-2 font-mono">
                                        {{ rate.quote_currency }} →
                                        {{ base_currency }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right font-mono tabular-nums"
                                    >
                                        {{ rate.rate }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ fmt.date(rate.effective_date) }}
                                    </td>
                                    <td class="px-3 py-2 text-muted-foreground">
                                        {{
                                            t(
                                                `scampause.policies.rate_source.${rate.source === 'seed' ? 'seed' : 'manual'}`,
                                            )
                                        }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <Button
                                            v-if="abilities.manage"
                                            variant="ghost"
                                            size="icon-sm"
                                            :aria-label="
                                                t('scampause.common.delete')
                                            "
                                            @click="removeRate(rate.id)"
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </ExhibitCard>
            </TabsContent>
        </Tabs>
    </div>

    <RuleSheet
        v-model:open="sheetOpen"
        :rule="editing"
        :base-currency="base_currency"
        :options="options"
    />
    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="t('scampause.policies.delete_title')"
        :description="t('scampause.policies.delete_body')"
        :confirm-label="t('scampause.common.delete')"
        destructive
        @confirm="destroyRule"
    />
</template>
