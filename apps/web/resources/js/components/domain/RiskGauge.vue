<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { t } from '@/lib/i18n';
import {
    clampScore,
    RISK_ICONS,
    RISK_STROKE,
    RISK_TEXT,
    riskLevelFor,
} from '@/lib/risk';
import type { RiskLevel } from '@/lib/risk';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        score: number;
        level?: RiskLevel | null;
        size?: 'sm' | 'md' | 'lg';
        animate?: boolean;
        showLabel?: boolean;
        delay?: number;
        class?: string;
    }>(),
    {
        level: null,
        delay: 0,
        size: 'md',
        animate: true,
        showLabel: true,
        class: '',
    },
);

const reduced = useReducedMotion();
const value = computed(() => clampScore(props.score));
const resolvedLevel = computed<RiskLevel>(
    () => props.level ?? riskLevelFor(value.value),
);
const swept = ref(!props.animate);

onMounted(() => {
    if (!props.animate || reduced.value) {
        swept.value = true;

        return;
    }

    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            swept.value = true;
        });
    });
});

const offset = computed(() => (swept.value ? 100 - value.value : 100));

const widths = { sm: 'w-24', md: 'w-36', lg: 'w-48' } as const;
const scoreSizes = {
    sm: 'text-xl font-semibold',
    md: 'text-kpi',
    lg: 'text-kpi text-[2.75rem]',
} as const;

const label = computed(() => t(`ui.risk.levels.${resolvedLevel.value}`));
</script>

<template>
    <figure
        :class="
            cn('inline-flex flex-col items-center', widths[size], $props.class)
        "
        role="img"
        :aria-label="t('ui.risk.gauge_label', { score: value, level: label })"
        data-test="risk-gauge"
    >
        <div class="relative w-full">
            <svg
                viewBox="0 0 120 68"
                class="w-full overflow-visible"
                aria-hidden="true"
            >
                <path
                    d="M10 62a50 50 0 0 1 100 0"
                    pathLength="100"
                    fill="none"
                    class="stroke-muted"
                    stroke-width="10"
                    stroke-linecap="round"
                />
                <path
                    d="M10 62a50 50 0 0 1 100 0"
                    pathLength="100"
                    fill="none"
                    :class="[
                        RISK_STROKE[resolvedLevel],
                        animate && !reduced
                            ? 'transition-[stroke-dashoffset] duration-[900ms] ease-story'
                            : '',
                    ]"
                    stroke-width="10"
                    stroke-linecap="round"
                    stroke-dasharray="100 100"
                    :stroke-dashoffset="offset"
                    :style="
                        animate && !reduced && delay
                            ? { transitionDelay: `${delay}ms` }
                            : undefined
                    "
                />
            </svg>
            <span
                :class="[
                    'absolute inset-x-0 bottom-0 text-center leading-none tabular-nums',
                    scoreSizes[size],
                ]"
                aria-hidden="true"
                >{{ value }}</span
            >
        </div>
        <figcaption
            v-if="showLabel"
            :class="[
                'mt-1.5 inline-flex items-center gap-1 text-xs font-semibold whitespace-nowrap',
                RISK_TEXT[resolvedLevel],
            ]"
            aria-hidden="true"
        >
            <component
                :is="RISK_ICONS[resolvedLevel]"
                class="size-3.5"
                :stroke-width="2"
            />
            {{ label }}
        </figcaption>
    </figure>
</template>
