<script setup lang="ts">
import {
    ArrowRightLeft,
    AtSign,
    CalendarClock,
    EyeOff,
    FileDown,
    Fingerprint,
    Landmark,
    Link2,
    MessagesSquare,
    ScrollText,
    ShieldCheck,
    TrendingUp,
} from '@lucide/vue';
import { siTelegram } from 'simple-icons';
import { computed } from 'vue';
import BrandMark from '@/components/brand/BrandMark.vue';
import BankDiffTable from '@/components/domain/BankDiffTable.vue';
import RiskBadge from '@/components/domain/RiskBadge.vue';
import SignalChip from '@/components/domain/SignalChip.vue';
import Reveal from '@/components/fx/Reveal.vue';
import PolicySimulatorMini from '@/components/marketing/landing/PolicySimulatorMini.vue';
import SectionHeading from '@/components/marketing/SectionHeading.vue';
import { t } from '@/lib/i18n';
import type { BankDiffRow } from '@/types/brand';

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
    {
        key: 'country',
        label: t('marketing.mock.bank.country'),
        onRecord: t('marketing.mock.bank.country_old'),
        inRequest: t('marketing.mock.bank.country_new'),
    },
]);

const chain = [
    { key: 'created', hash: '#2fda86' },
    { key: 'verified', hash: '#9c41e0' },
    { key: 'approved', hash: '#51b7aa' },
] as const;

const series = [0, 2400, 2400, 7900, 7900, 18400];
const chartPath = computed(() => {
    const max = Math.max(...series);
    const points = series.map((value, index) => {
        const x = (index / (series.length - 1)) * 200;
        const y = 70 - (value / max) * 62;

        return `${x.toFixed(1)},${y.toFixed(1)}`;
    });

    return {
        line: `M${points.join(' L')}`,
        area: `M0,72 L${points.join(' L')} L200,72 Z`,
    };
});

const tile =
    'relative flex flex-col overflow-hidden rounded-xl border bg-card p-5 shadow-xs transition-colors duration-100 hover:border-ring/40';
</script>

<template>
    <section
        id="product"
        class="scroll-mt-28 px-4 py-16 sm:px-6 lg:py-24"
        aria-labelledby="product-title"
    >
        <div class="mx-auto max-w-[75rem]">
            <SectionHeading
                id="product-title"
                :eyebrow="t('marketing.bento.eyebrow')"
                :title="t('marketing.bento.title')"
                :description="t('marketing.bento.subtitle')"
            />

            <div
                class="mt-10 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4"
            >
                <Reveal
                    as="article"
                    :class="[tile, 'md:col-span-2 lg:row-span-2']"
                >
                    <ArrowRightLeft
                        class="size-6 text-primary"
                        :stroke-width="1.5"
                        aria-hidden="true"
                    />
                    <h3 class="mt-4 font-display text-xl font-semibold">
                        {{ t('marketing.bento.diff.title') }}
                    </h3>
                    <p
                        class="mt-2 max-w-md text-sm leading-relaxed text-pretty text-muted-foreground"
                    >
                        {{ t('marketing.bento.diff.body') }}
                    </p>
                    <div class="mt-6 flex flex-wrap items-center gap-2">
                        <RiskBadge level="critical" :score="92" size="md" />
                        <SignalChip
                            :icon="Landmark"
                            :title="t('marketing.mock.signals.new_account')"
                            :points="35"
                        />
                    </div>
                    <div class="mt-auto pt-6">
                        <p class="mb-2 eyebrow text-muted-foreground">
                            {{ t('marketing.mock.exhibit_bank') }}
                        </p>
                        <BankDiffTable
                            :rows="rows"
                            :caption="t('marketing.bento.diff.title')"
                        />
                        <div class="mt-5">
                            <p class="mb-2 eyebrow text-muted-foreground">
                                {{ t('marketing.bento.diff.history') }}
                            </p>
                            <ol class="space-y-1.5 text-sm">
                                <li class="flex items-center gap-2.5">
                                    <span
                                        class="size-2 shrink-0 rounded-[2px] bg-success"
                                        aria-hidden="true"
                                    />
                                    <span class="font-mono tabular-nums">{{
                                        t('marketing.mock.bank.account_old')
                                    }}</span>
                                    <span class="text-muted-foreground">{{
                                        t(
                                            'marketing.bento.diff.history_verified',
                                        )
                                    }}</span>
                                </li>
                                <li class="flex items-center gap-2.5">
                                    <span
                                        class="size-2 shrink-0 rounded-[2px] bg-destructive"
                                        aria-hidden="true"
                                    />
                                    <span class="font-mono tabular-nums"
                                        >•••• 2290</span
                                    >
                                    <span class="text-muted-foreground">{{
                                        t(
                                            'marketing.bento.diff.history_rejected',
                                        )
                                    }}</span>
                                </li>
                            </ol>
                        </div>
                        <p
                            class="mt-3 flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <EyeOff
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            {{ t('marketing.bento.diff.reveal') }}
                        </p>
                    </div>
                </Reveal>

                <Reveal as="article" :delay="0.04" :class="tile">
                    <AtSign
                        class="size-6 text-primary"
                        :stroke-width="1.5"
                        aria-hidden="true"
                    />
                    <h3 class="mt-4 font-display text-lg font-semibold">
                        {{ t('marketing.bento.sender.title') }}
                    </h3>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        {{ t('marketing.bento.sender.body') }}
                    </p>
                    <div class="mt-auto space-y-2 pt-5 text-xs">
                        <p
                            class="truncate rounded-sm bg-risk-critical-tint px-2 py-1.5 font-mono text-risk-critical"
                        >
                            {{ t('marketing.mock.sender_domain') }}
                        </p>
                        <p class="flex flex-wrap gap-1">
                            <span
                                class="rounded-sm bg-muted px-1.5 py-0.5 font-mono text-muted-foreground"
                                >{{ t('marketing.bento.sender.auth') }}</span
                            >
                            <span
                                class="rounded-sm bg-risk-high-tint px-1.5 py-0.5 font-mono text-risk-high"
                                >{{
                                    t('marketing.bento.sender.auth_fail')
                                }}</span
                            >
                        </p>
                        <p
                            class="flex items-center gap-1.5 text-muted-foreground"
                        >
                            <CalendarClock
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            {{ t('marketing.bento.sender.age') }}
                        </p>
                    </div>
                </Reveal>

                <Reveal as="article" :delay="0.08" :class="tile">
                    <BrandMark :icon="siTelegram" class="size-6 text-info" />
                    <h3 class="mt-4 font-display text-lg font-semibold">
                        {{ t('marketing.bento.telegram.title') }}
                    </h3>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        {{ t('marketing.bento.telegram.body') }}
                    </p>
                    <div
                        class="mt-auto grid grid-cols-2 gap-1.5 pt-5"
                        aria-hidden="true"
                    >
                        <span
                            class="inline-flex h-8 items-center justify-center rounded-md border border-destructive/60 text-xs font-semibold text-destructive"
                            >{{ t('marketing.mock.telegram.reject') }}</span
                        >
                        <span
                            class="inline-flex h-8 items-center justify-center rounded-md bg-primary text-xs font-semibold text-primary-foreground"
                            >{{ t('marketing.mock.telegram.approve') }}</span
                        >
                    </div>
                </Reveal>

                <Reveal
                    as="article"
                    :delay="0.12"
                    :class="[tile, 'md:col-span-2']"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="font-display text-lg font-semibold">
                                {{ t('marketing.bento.policy.title') }}
                            </h3>
                            <p class="mt-1.5 text-sm text-muted-foreground">
                                {{ t('marketing.bento.policy.body') }}
                            </p>
                        </div>
                        <ScrollText
                            class="size-6 shrink-0 text-primary"
                            :stroke-width="1.5"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="mt-5">
                        <PolicySimulatorMini />
                    </div>
                </Reveal>

                <Reveal as="article" :delay="0.04" :class="tile">
                    <Fingerprint
                        class="size-6 text-primary"
                        :stroke-width="1.5"
                        aria-hidden="true"
                    />
                    <h3 class="mt-4 font-display text-lg font-semibold">
                        {{ t('marketing.bento.evidence.title') }}
                    </h3>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        {{ t('marketing.bento.evidence.body') }}
                    </p>
                    <ol class="mt-auto space-y-1 pt-5" aria-hidden="true">
                        <li
                            v-for="(block, index) in chain"
                            :key="block.key"
                            class="space-y-1"
                        >
                            <div
                                class="flex items-center justify-between gap-2 rounded-sm border bg-background px-2 py-1.5 text-xs"
                            >
                                <span class="truncate">{{
                                    t(`marketing.mock.evidence.${block.key}`)
                                }}</span>
                                <span class="font-mono text-muted-foreground">{{
                                    block.hash
                                }}</span>
                            </div>
                            <Link2
                                v-if="index < chain.length - 1"
                                class="mx-auto size-3 text-muted-foreground"
                            />
                        </li>
                    </ol>
                    <p
                        class="mt-3 flex items-center gap-1.5 text-xs font-medium text-success"
                    >
                        <ShieldCheck class="size-3.5" aria-hidden="true" />
                        {{ t('marketing.mock.evidence.integrity') }}
                    </p>
                </Reveal>

                <Reveal
                    as="article"
                    :delay="0.08"
                    :class="[tile, 'md:col-span-2']"
                >
                    <MessagesSquare
                        class="size-6 text-primary"
                        :stroke-width="1.5"
                        aria-hidden="true"
                    />
                    <h3 class="mt-4 font-display text-lg font-semibold">
                        {{ t('marketing.bento.assistant.title') }}
                    </h3>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        {{ t('marketing.bento.assistant.body') }}
                    </p>
                    <div class="mt-auto space-y-2 pt-5 text-sm">
                        <p
                            class="ml-auto w-fit max-w-[85%] rounded-lg bg-secondary px-3 py-2 text-secondary-foreground"
                        >
                            {{ t('marketing.bento.assistant.question') }}
                        </p>
                        <div
                            class="max-w-[90%] rounded-lg border border-l-2 border-l-primary bg-card px-3 py-2"
                        >
                            <p>
                                {{ t('marketing.bento.assistant.answer') }}
                                <span
                                    class="ml-0.5 inline-flex h-4 items-center rounded-[4px] border border-primary/40 bg-primary-soft px-1 align-text-top font-mono text-[0.625rem] font-semibold text-primary"
                                    >1</span
                                >
                            </p>
                            <p
                                class="mt-2 inline-flex items-center gap-1.5 rounded-sm border px-1.5 py-0.5 text-xs text-muted-foreground"
                            >
                                <FileDown class="size-3.5" aria-hidden="true" />
                                {{ t('marketing.bento.assistant.source') }}
                            </p>
                        </div>
                    </div>
                </Reveal>

                <Reveal as="article" :delay="0.12" :class="tile">
                    <TrendingUp
                        class="size-6 text-success"
                        :stroke-width="1.5"
                        aria-hidden="true"
                    />
                    <h3 class="mt-4 font-display text-lg font-semibold">
                        {{ t('marketing.bento.prevented.title') }}
                    </h3>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        {{ t('marketing.bento.prevented.body') }}
                    </p>
                    <svg
                        viewBox="0 0 200 72"
                        class="mt-auto h-20 w-full pt-4"
                        preserveAspectRatio="none"
                        role="img"
                        :aria-label="t('marketing.bento.prevented.chart_label')"
                    >
                        <path d="M0 71.5h200" class="stroke-grid-line" />
                        <path :d="chartPath.area" class="fill-success/15" />
                        <path
                            :d="chartPath.line"
                            fill="none"
                            class="stroke-success"
                            stroke-width="2"
                            vector-effect="non-scaling-stroke"
                            stroke-linejoin="round"
                        />
                    </svg>
                </Reveal>
            </div>
        </div>
    </section>
</template>
