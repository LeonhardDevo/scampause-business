<script setup lang="ts">
import {
    AtSign,
    Banknote,
    Clock,
    Landmark,
    MessageSquareWarning,
    PhoneCall,
    PhoneOff,
} from '@lucide/vue';
import { siTelegram } from 'simple-icons';
import { computed } from 'vue';
import BrandMark from '@/components/brand/BrandMark.vue';
import BankDiffTable from '@/components/domain/BankDiffTable.vue';
import type { BankDiffRow } from '@/types/brand';
import RiskGauge from '@/components/domain/RiskGauge.vue';
import SignalChip from '@/components/domain/SignalChip.vue';
import { useFormat } from '@/composables/useFormat';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { t } from '@/lib/i18n';

const reduced = useReducedMotion();
const fmt = useFormat();

const amount = computed(() =>
    fmt.value.money(18400, 'USD', { fractionDigits: 2 }),
);

const signals = computed(() => [
    { key: 'lookalike', icon: AtSign, points: 30 },
    { key: 'reply_to', icon: MessageSquareWarning, points: 20 },
    { key: 'dont_call', icon: PhoneOff, points: 20 },
    { key: 'new_account', icon: Landmark, points: 35 },
]);

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

function chipDelay(index: number): Record<string, string> | undefined {
    return reduced.value
        ? undefined
        : { animationDelay: `${700 + index * 40}ms` };
}
</script>

<template>
    <figure
        class="relative mx-auto w-full max-w-[34rem]"
        :aria-label="t('marketing.mock.label')"
        data-test="hero-mockup"
    >
        <div
            class="relative overflow-hidden rounded-xl border bg-card shadow-lg"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b bg-muted/50 px-4 py-2.5"
            >
                <p class="flex min-w-0 items-center gap-2 text-sm">
                    <span
                        class="shrink-0 mono-data whitespace-nowrap text-muted-foreground"
                        >{{ t('marketing.mock.reference') }}</span
                    >
                    <span class="truncate font-semibold">{{
                        t('marketing.mock.vendor')
                    }}</span>
                </p>
                <p
                    class="flex shrink-0 items-center gap-1.5 text-xs font-medium text-hazard-ink"
                >
                    <Clock class="size-3.5" aria-hidden="true" />
                    {{ t('marketing.mock.due') }}
                </p>
            </div>

            <div class="relative px-4 pt-4 pb-3">
                <p class="eyebrow text-muted-foreground">
                    {{ t('marketing.mock.exhibit_sender') }}
                </p>
                <dl
                    class="mt-2.5 grid grid-cols-[4.75rem_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm"
                >
                    <dt class="text-muted-foreground">
                        {{ t('marketing.mock.from') }}
                    </dt>
                    <dd class="min-w-0 truncate">
                        <span class="font-medium">{{
                            t('marketing.mock.sender_name')
                        }}</span>
                        <span class="font-mono text-[0.8125rem]">
                            &lt;karen@{{ t('marketing.mock.sender_domain_lead')
                            }}<mark
                                class="rounded-[2px] bg-risk-critical-tint px-0.5 font-semibold text-risk-critical underline decoration-2 underline-offset-2"
                                >{{
                                    t('marketing.mock.sender_domain_odd')
                                }}</mark
                            >{{ t('marketing.mock.sender_domain_tail') }}&gt;
                        </span>
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('marketing.mock.reply_to') }}
                    </dt>
                    <dd
                        class="min-w-0 truncate font-mono text-[0.8125rem] text-risk-high"
                    >
                        {{ t('marketing.mock.reply_to_value') }}
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('marketing.mock.subject') }}
                    </dt>
                    <dd class="min-w-0 truncate font-medium">
                        {{ t('marketing.mock.subject_value') }}
                    </dd>
                </dl>
                <div
                    class="mt-3 space-y-1.5 border-t border-dashed pt-3 text-sm"
                >
                    <p class="text-muted-foreground">
                        {{ t('marketing.mock.body_line') }}
                    </p>
                    <p>
                        <mark
                            class="rounded-[2px] bg-risk-high-tint px-1 text-risk-high"
                            >{{ t('marketing.mock.body_pressure') }}</mark
                        >
                    </p>
                    <p class="flex gap-1.5 pt-0.5" aria-hidden="true">
                        <span class="redaction-bar w-16 opacity-15" />
                        <span class="redaction-bar w-24 opacity-15" />
                        <span class="redaction-bar w-10 opacity-15" />
                    </p>
                </div>
                <p
                    class="mt-2.5 text-xs text-muted-foreground"
                    aria-hidden="true"
                >
                    {{ t('marketing.mock.lookalike_note') }}
                </p>

                <div
                    v-if="!reduced"
                    class="pointer-events-none absolute inset-x-0 top-0 h-10 animate-scan [--scan-distance:15rem]"
                    aria-hidden="true"
                >
                    <div
                        class="h-full bg-gradient-to-b from-transparent to-primary/10"
                    />
                    <div class="h-px bg-primary" />
                </div>
            </div>

            <ul
                class="flex flex-wrap gap-1.5 border-t bg-muted/30 px-4 py-3"
                :aria-label="t('marketing.mock.exhibit_score')"
            >
                <li
                    v-for="(signal, index) in signals"
                    :key="signal.key"
                    class="motion-safe:animate-fade-up"
                    :style="chipDelay(index)"
                >
                    <SignalChip
                        :icon="signal.icon"
                        :title="t(`marketing.mock.signals.${signal.key}`)"
                        :points="signal.points"
                    />
                </li>
            </ul>

            <div
                class="grid grid-cols-1 items-center gap-4 border-t px-4 py-4 sm:grid-cols-[minmax(0,1fr)_6.5rem]"
            >
                <div class="min-w-0">
                    <p class="mb-2 eyebrow text-muted-foreground">
                        {{ t('marketing.mock.exhibit_bank') }}
                    </p>
                    <BankDiffTable
                        dense
                        :rows="rows"
                        :caption="t('marketing.mock.exhibit_bank')"
                    />
                </div>
                <div class="flex items-center justify-between gap-4 sm:block">
                    <RiskGauge
                        :score="92"
                        level="critical"
                        size="sm"
                        class="sm:w-full"
                        :delay="900"
                    />
                    <p
                        class="flex items-center gap-1.5 text-sm font-semibold whitespace-nowrap sm:mt-2 sm:justify-center"
                    >
                        <Banknote
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="tabular-nums">{{ amount }}</span>
                    </p>
                </div>
            </div>

            <p
                class="hazard-edge-l flex items-start gap-2 border-t bg-risk-critical-tint px-4 py-3 pl-5 text-sm font-medium text-risk-critical"
            >
                <PhoneCall class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                {{ t('marketing.mock.advice') }}
            </p>
        </div>

        <div
            class="dark relative mx-4 mt-3 rounded-xl border bg-sidebar p-3.5 text-foreground shadow-lg motion-safe:animate-fade-up sm:-mt-4 sm:mr-auto sm:-ml-8 sm:w-[19rem]"
            :style="reduced ? undefined : { animationDelay: '1200ms' }"
        >
            <div class="flex items-center gap-2">
                <span
                    class="inline-flex size-7 items-center justify-center rounded-full bg-info/15 text-info"
                >
                    <BrandMark :icon="siTelegram" class="size-4" />
                </span>
                <span class="text-sm font-semibold">{{
                    t('marketing.mock.telegram.bot')
                }}</span>
                <span
                    class="ml-auto font-mono text-[0.6875rem] text-muted-foreground"
                    >09:41</span
                >
            </div>
            <div class="mt-2.5 rounded-lg bg-card p-3 text-sm">
                <p class="font-semibold">
                    {{ t('marketing.mock.telegram.title') }}
                </p>
                <p class="mt-1 text-muted-foreground">
                    {{ t('marketing.mock.telegram.body') }}
                </p>
                <p class="mt-2 text-xs text-hazard-ink">
                    {{ t('marketing.mock.telegram.web_only') }}
                </p>
            </div>
            <div class="mt-2 grid grid-cols-2 gap-1.5" aria-hidden="true">
                <span
                    class="inline-flex h-8 items-center justify-center rounded-md border border-destructive/60 text-xs font-semibold text-destructive"
                    >{{ t('marketing.mock.telegram.reject') }}</span
                >
                <span
                    class="inline-flex h-8 items-center justify-center rounded-md bg-primary text-xs font-semibold text-primary-foreground"
                    >{{ t('marketing.mock.telegram.open') }}</span
                >
            </div>
        </div>
    </figure>
</template>
