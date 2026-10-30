<script setup lang="ts">
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { t } from '@/lib/i18n';
import { clampScore, RISK_ICONS, RISK_TINT } from '@/lib/risk';
import type { RiskLevel } from '@/lib/risk';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        level: RiskLevel;
        score?: number | null;
        size?: 'sm' | 'md';
        tooltip?: boolean;
        class?: string;
    }>(),
    { score: null, size: 'sm', tooltip: true, class: '' },
);

const label = computed(() => t(`ui.risk.levels.${props.level}`));
</script>

<template>
    <TooltipProvider :delay-duration="200" :disabled="!tooltip">
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    :class="
                        cn(
                            'relative inline-flex shrink-0 items-center gap-1.5 overflow-hidden rounded-sm font-medium whitespace-nowrap',
                            size === 'sm'
                                ? 'h-6 px-2 text-xs'
                                : 'h-7 px-2.5 text-sm',
                            RISK_TINT[level],
                            level === 'critical' ? 'hazard-edge-l pl-3' : '',
                            $props.class,
                        )
                    "
                    :tabindex="tooltip ? 0 : undefined"
                    data-test="risk-badge"
                    :data-level="level"
                >
                    <component
                        :is="RISK_ICONS[level]"
                        :class="size === 'sm' ? 'size-3.5' : 'size-4'"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                    {{ label }}
                    <template v-if="score !== null">
                        <span aria-hidden="true">·</span>
                        <span class="font-mono tabular-nums">{{
                            clampScore(score)
                        }}</span>
                    </template>
                </span>
            </TooltipTrigger>
            <TooltipContent class="max-w-64 text-pretty">
                {{ t('ui.risk.disclaimer') }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
