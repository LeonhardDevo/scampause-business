<script setup lang="ts">
import {
    AtSign,
    Ban,
    CheckCheck,
    Clock,
    Copy,
    FileText,
    Landmark,
    MessageSquareWarning,
    PhoneCall,
    PhoneOff,
    Stamp,
    Upload,
} from '@lucide/vue';
import { computed } from 'vue';
import RiskGauge from '@/components/domain/RiskGauge.vue';
import SignalChip from '@/components/domain/SignalChip.vue';
import { t } from '@/lib/i18n';
import type { StoryStep } from '@/types/brand';

defineProps<{ step: StoryStep }>();

const pipeline = ['extract', 'domains', 'bank', 'score'] as const;

const signals = computed(() => [
    { key: 'new_account', icon: Landmark, points: 35 },
    { key: 'lookalike', icon: AtSign, points: 30 },
    { key: 'reply_to', icon: MessageSquareWarning, points: 20 },
    { key: 'dont_call', icon: PhoneOff, points: 20 },
]);
</script>

<template>
    <div class="rounded-xl border bg-card p-4 shadow-lg sm:p-5">
        <template v-if="step === 'forward'">
            <div
                class="flex min-h-44 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-input bg-grid-paper px-4 py-6 text-center"
            >
                <span
                    class="inline-flex size-10 items-center justify-center rounded-md border bg-card text-primary"
                >
                    <Upload class="size-5" aria-hidden="true" />
                </span>
                <p class="text-sm font-medium">
                    {{ t('marketing.how.upload.drop') }}
                </p>
                <p class="font-mono text-xs text-muted-foreground">
                    {{ t('marketing.how.upload.types') }}
                </p>
            </div>
            <div
                class="mt-3 flex items-center gap-3 rounded-lg border bg-background px-3 py-2.5"
            >
                <FileText
                    class="size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-mono text-sm">{{
                        t('marketing.how.upload.file')
                    }}</span>
                    <span class="block text-xs text-muted-foreground">{{
                        t('marketing.how.upload.extracted')
                    }}</span>
                </span>
                <CheckCheck class="size-4 text-success" aria-hidden="true" />
            </div>
            <ol class="mt-4 grid grid-cols-4 gap-1.5">
                <li v-for="stage in pipeline" :key="stage" class="space-y-1.5">
                    <span class="block h-1 rounded-full bg-primary" />
                    <span
                        class="block truncate font-mono text-[0.6875rem] text-muted-foreground"
                        >{{ t(`marketing.how.pipeline.${stage}`) }}</span
                    >
                </li>
            </ol>
        </template>

        <template v-else-if="step === 'check'">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="eyebrow text-muted-foreground">
                        {{ t('marketing.mock.exhibit_score') }}
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        <span class="mr-2 mono-data text-muted-foreground">{{
                            t('marketing.mock.reference')
                        }}</span>
                        {{ t('marketing.mock.vendor') }}
                    </p>
                </div>
                <RiskGauge :score="92" level="critical" size="sm" />
            </div>
            <ul class="mt-4 divide-y rounded-lg border">
                <li
                    v-for="signal in signals"
                    :key="signal.key"
                    class="flex items-center justify-between gap-3 px-3 py-2.5"
                >
                    <SignalChip
                        :icon="signal.icon"
                        :title="t(`marketing.mock.signals.${signal.key}`)"
                        class="border-transparent bg-transparent px-0 shadow-none"
                    />
                    <span
                        class="font-mono text-sm font-semibold text-risk-critical tabular-nums"
                        >+{{ signal.points }}</span
                    >
                </li>
            </ul>
            <p class="mt-3 text-xs text-muted-foreground">
                {{ t('marketing.mock.checked') }}
            </p>
        </template>

        <template v-else>
            <p class="eyebrow text-muted-foreground">
                {{ t('marketing.how.callback.title') }}
            </p>
            <p
                class="mt-2 flex items-center gap-2 rounded-md bg-warning-soft px-2.5 py-1.5 text-xs font-medium text-warning-soft-foreground"
            >
                <Ban class="size-3.5 shrink-0" aria-hidden="true" />
                {{ t('marketing.how.callback.do_not_use') }}
            </p>
            <div
                class="mt-3 flex items-center gap-3 rounded-lg border bg-background p-3"
            >
                <span
                    class="inline-flex size-9 shrink-0 items-center justify-center rounded-md bg-success-soft text-success-soft-foreground"
                >
                    <PhoneCall class="size-4" aria-hidden="true" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">{{
                        t('marketing.how.callback.contact')
                    }}</span>
                    <span class="block font-mono text-sm tabular-nums">
                        <span aria-hidden="true">{{
                            t('marketing.how.callback.number')
                        }}</span>
                        <span class="sr-only">{{
                            t('marketing.how.callback.number_sr')
                        }}</span>
                    </span>
                    <span class="block text-xs text-muted-foreground">{{
                        t('marketing.how.callback.source')
                    }}</span>
                </span>
                <Copy
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            </div>
            <p
                class="mt-3 flex items-center gap-2 text-sm font-medium text-success"
            >
                <CheckCheck class="size-4" aria-hidden="true" />
                {{ t('marketing.how.callback.outcome') }}
            </p>
            <div class="mt-4 rounded-lg border">
                <p
                    class="flex items-center justify-between gap-2 border-b px-3 py-2 text-xs"
                >
                    <span class="eyebrow text-muted-foreground">{{
                        t('marketing.mock.exhibit_approval')
                    }}</span>
                    <span class="font-mono text-muted-foreground">{{
                        t('marketing.mock.approvals.policy')
                    }}</span>
                </p>
                <ul class="divide-y text-sm">
                    <li class="flex items-center gap-3 px-3 py-2.5">
                        <Stamp class="size-4 text-success" aria-hidden="true" />
                        <span class="flex-1">{{
                            t('marketing.mock.approvals.owner')
                        }}</span>
                        <span
                            class="rounded-sm bg-success-soft px-1.5 py-0.5 text-xs font-medium text-success-soft-foreground"
                            >{{ t('marketing.mock.approvals.approved') }}</span
                        >
                    </li>
                    <li class="flex items-center gap-3 px-3 py-2.5">
                        <Clock
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="flex-1">{{
                            t('marketing.mock.approvals.finance')
                        }}</span>
                        <span
                            class="rounded-sm bg-info-soft px-1.5 py-0.5 text-xs font-medium text-info-soft-foreground"
                            >{{ t('marketing.mock.approvals.waiting') }}</span
                        >
                    </li>
                </ul>
            </div>
        </template>
    </div>
</template>
