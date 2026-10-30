<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import { severityBars } from '@/lib/risk';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        title: string;
        points?: number | null;
        icon?: Component;
        class?: string;
    }>(),
    { points: null, icon: undefined, class: '' },
);

const bars = computed(() =>
    props.points === null ? 0 : severityBars(props.points),
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex h-7 max-w-full items-center gap-2 rounded-sm border bg-card px-2 text-xs font-medium text-foreground shadow-xs',
                $props.class,
            )
        "
    >
        <component
            :is="icon"
            v-if="icon"
            class="size-3.5 shrink-0 text-hazard-ink"
            :stroke-width="2"
            aria-hidden="true"
        />
        <span class="truncate">{{ title }}</span>
        <template v-if="points !== null">
            <span
                class="flex items-end gap-px"
                role="img"
                :aria-label="t('ui.risk.severity', { bars, max: 4 })"
            >
                <span
                    v-for="bar in 4"
                    :key="bar"
                    :class="[
                        'w-[3px] rounded-[1px]',
                        bar <= bars ? 'bg-hazard-ink' : 'bg-muted',
                    ]"
                    :style="{ height: `${4 + bar * 2}px` }"
                />
            </span>
            <span class="font-mono text-muted-foreground tabular-nums"
                >+{{ points }}</span
            >
        </template>
    </span>
</template>
