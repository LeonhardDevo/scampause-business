<script setup lang="ts">
import { AtSign, MessageSquareWarning, PhoneOff } from '@lucide/vue';
import { computed } from 'vue';
import BankDiffTable from '@/components/domain/BankDiffTable.vue';
import type { BankDiffRow } from '@/types/brand';
import RiskGauge from '@/components/domain/RiskGauge.vue';
import SignalChip from '@/components/domain/SignalChip.vue';
import { t } from '@/lib/i18n';

const steps = ['stop', 'call', 'confirm'] as const;

const rows = computed<BankDiffRow[]>(() => [
    {
        key: 'account',
        label: t('marketing.mock.bank.account'),
        onRecord: t('marketing.mock.bank.account_old'),
        inRequest: t('marketing.mock.bank.account_new'),
        srOnRecord: t('marketing.mock.bank.account_old_sr'),
        srInRequest: t('marketing.mock.bank.account_new_sr'),
        changed: true,
        mono: true,
    },
    {
        key: 'bank',
        label: t('marketing.mock.bank.bank'),
        onRecord: t('marketing.mock.bank.bank_old'),
        inRequest: t('marketing.mock.bank.bank_new'),
        changed: true,
    },
]);
</script>

<template>
    <div
        class="dark relative flex h-full flex-col overflow-hidden bg-sidebar px-10 py-10 text-foreground xl:px-14"
    >
        <div
            class="pointer-events-none absolute inset-0 bg-grid-paper mask-fade-top"
            aria-hidden="true"
        />
        <div class="relative">
            <p class="flex items-center gap-2 eyebrow text-muted-foreground">
                <span
                    class="inline-block h-3 w-6 rounded-[1px] hazard-stripe"
                />
                {{ t('marketing.auth.eyebrow') }}
            </p>
            <p
                class="mt-5 max-w-md text-display text-[clamp(2.25rem,3vw,3.25rem)] text-balance"
            >
                {{ t('marketing.auth.headline') }}
            </p>
            <p
                class="mt-4 max-w-md text-base text-pretty text-muted-foreground"
            >
                {{ t('marketing.auth.subhead') }}
            </p>
        </div>

        <ol class="relative mt-8 grid max-w-lg gap-3">
            <li
                v-for="(step, index) in steps"
                :key="step"
                class="flex gap-4 rounded-lg border bg-card/60 p-3.5"
            >
                <span
                    class="inline-flex h-7 w-9 shrink-0 items-center justify-center rounded-sm bg-hazard mono-data text-xs font-semibold text-hazard-foreground"
                    aria-hidden="true"
                    >0{{ index + 1 }}</span
                >
                <span class="min-w-0">
                    <span class="block text-sm font-semibold">{{
                        t(`marketing.auth.steps.${step}.title`)
                    }}</span>
                    <span class="mt-0.5 block text-sm text-muted-foreground">{{
                        t(`marketing.auth.steps.${step}.body`)
                    }}</span>
                </span>
            </li>
        </ol>

        <figure
            class="relative mt-auto max-w-lg pt-10"
            :aria-label="t('marketing.auth.preview_label')"
        >
            <div class="rounded-xl border bg-card p-4 shadow-lg">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="eyebrow text-muted-foreground">
                            {{ t('marketing.mock.exhibit_bank') }}
                        </p>
                        <p class="mt-1.5 truncate text-sm font-semibold">
                            <span
                                class="mr-2 mono-data text-muted-foreground"
                                >{{ t('marketing.mock.reference') }}</span
                            >
                            {{ t('marketing.mock.vendor') }}
                        </p>
                    </div>
                    <RiskGauge :score="92" level="critical" size="sm" />
                </div>
                <BankDiffTable
                    class="mt-3"
                    dense
                    :rows="rows"
                    :caption="t('marketing.mock.exhibit_bank')"
                />
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <SignalChip
                        :icon="AtSign"
                        :title="t('marketing.mock.signals.lookalike')"
                    />
                    <SignalChip
                        :icon="MessageSquareWarning"
                        :title="t('marketing.mock.signals.reply_to')"
                    />
                    <SignalChip
                        :icon="PhoneOff"
                        :title="t('marketing.mock.signals.dont_call')"
                    />
                </div>
            </div>
            <figcaption class="mt-4 text-xs text-muted-foreground">
                {{ t('marketing.auth.disclaimer') }}
            </figcaption>
        </figure>
    </div>
</template>
