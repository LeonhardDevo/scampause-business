<script setup lang="ts">
import { PhoneCall, Stamp, UserRoundCheck } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { t, tc } from '@/lib/i18n';
import {
    DEMO_POLICY_BANDS,
    parseDemoAmount,
    simulateDemoPolicy,
} from '@/lib/policy-demo';
import { cn } from '@/lib/utils';

const fmt = useFormat();
const inputId = useId();
const resultId = useId();

const raw = ref('4000');
const amount = computed(() => parseDemoAmount(raw.value));
const result = computed(() => simulateDemoPolicy(amount.value));

const presets = [800, 4000, 18400];

const rules = computed(() =>
    [...DEMO_POLICY_BANDS]
        .filter((band) => band.threshold > 0)
        .reverse()
        .map((band) => ({
            threshold: band.threshold,
            text: t('marketing.bento.policy.rule', {
                amount: fmt.value.money(band.threshold, 'USD', {
                    fractionDigits: 0,
                }),
                approvers: [
                    tc('marketing.bento.policy.approvers', band.approvers),
                    band.ownerRequired
                        ? t('marketing.bento.policy.owner')
                        : null,
                ]
                    .filter(Boolean)
                    .join(' + '),
            }),
        })),
);
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div class="space-y-3">
            <div class="space-y-1.5">
                <Label :for="inputId">{{
                    t('marketing.bento.policy.amount_label')
                }}</Label>
                <Input
                    :id="inputId"
                    v-model="raw"
                    inputmode="decimal"
                    autocomplete="off"
                    class="h-11 font-mono text-base tabular-nums"
                    :aria-describedby="resultId"
                    data-test="policy-simulator-input"
                />
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="preset in presets"
                    :key="preset"
                    type="button"
                    :class="
                        cn(
                            'inline-flex h-8 items-center rounded-sm border px-2 font-mono text-xs tabular-nums transition-colors duration-100 hover:bg-accent pointer-coarse:h-11',
                            amount === preset
                                ? 'border-ring bg-accent text-accent-foreground'
                                : 'bg-card text-muted-foreground',
                        )
                    "
                    @click="raw = String(preset)"
                >
                    {{ fmt.money(preset, 'USD', { fractionDigits: 0 }) }}
                </button>
            </div>
            <ul class="space-y-1 font-mono text-xs text-muted-foreground">
                <li
                    v-for="rule in rules"
                    :key="rule.threshold"
                    :class="
                        amount >= rule.threshold &&
                        result.threshold === rule.threshold
                            ? 'text-foreground'
                            : ''
                    "
                >
                    {{ rule.text }}
                </li>
            </ul>
        </div>
        <div
            :id="resultId"
            class="rounded-lg border bg-background p-3.5"
            aria-live="polite"
            data-test="policy-simulator-result"
        >
            <p class="eyebrow text-muted-foreground">
                {{ t('marketing.bento.policy.result_label') }}
            </p>
            <p class="mt-2 flex items-center gap-2 text-kpi text-2xl">
                <Stamp
                    class="size-5 shrink-0 text-primary"
                    aria-hidden="true"
                />
                {{ tc('marketing.bento.policy.approvers', result.approvers) }}
            </p>
            <ul class="mt-3 space-y-1.5 text-sm">
                <li
                    v-if="result.callbackRequired"
                    class="flex items-center gap-2"
                >
                    <PhoneCall
                        class="size-4 shrink-0 text-hazard-ink"
                        aria-hidden="true"
                    />
                    {{ t('marketing.bento.policy.callback') }}
                </li>
                <li v-if="result.ownerRequired" class="flex items-center gap-2">
                    <UserRoundCheck
                        class="size-4 shrink-0 text-hazard-ink"
                        aria-hidden="true"
                    />
                    {{ t('marketing.bento.policy.owner') }}
                </li>
                <li
                    v-if="!result.callbackRequired && !result.ownerRequired"
                    class="text-muted-foreground"
                >
                    {{ t('marketing.bento.policy.none') }}
                </li>
            </ul>
        </div>
    </div>
</template>
