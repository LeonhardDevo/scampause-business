<script setup lang="ts">
import { TrendingDown, TrendingUp } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        label: string;
        value: number | string | null;
        delta?: number | null;
        invertDelta?: boolean;
        icon?: Component;
        format?: (value: number) => string;
        hint?: string | null;
        loading?: boolean;
        tone?: 'primary' | 'ai' | 'warning' | 'info';
    }>(),
    {
        delta: null,
        invertDelta: false,
        icon: undefined,
        format: undefined,
        hint: null,
        loading: false,
        tone: 'primary',
    },
);

const fmt = useFormat();

const deltaGood = computed(() => {
    if (props.delta === null || props.delta === undefined) {
        return null;
    }

    return props.invertDelta ? props.delta <= 0 : props.delta >= 0;
});

const toneClass = {
    primary: 'text-primary',
    ai: 'text-primary',
    warning: 'text-hazard-ink',
    info: 'text-info',
} as const;

const display = computed(() =>
    typeof props.value === 'number'
        ? (props.format ?? ((n: number) => fmt.value.number(n)))(props.value)
        : (props.value ?? '—'),
);
</script>

<template>
    <article
        class="relative flex flex-col gap-3 overflow-hidden rounded-xl border bg-card p-5 shadow-xs"
    >
        <div class="flex items-center justify-between gap-3">
            <p class="line-clamp-2 eyebrow text-muted-foreground">
                {{ label }}
            </p>
            <component
                :is="icon"
                v-if="icon"
                :class="['size-4 shrink-0', toneClass[tone]]"
                :stroke-width="1.75"
                aria-hidden="true"
            />
        </div>

        <template v-if="loading">
            <Skeleton class="h-8 w-28" />
            <Skeleton class="h-4 w-20" />
        </template>
        <template v-else>
            <p
                v-if="typeof value === 'number'"
                class="animate-fade-in truncate text-kpi"
            >
                {{ display }}
            </p>
            <p
                v-else
                class="block truncate py-1 mono-data text-lg leading-tight font-semibold"
                :title="value ?? undefined"
            >
                {{ display }}
            </p>
            <div class="flex min-h-5 items-center gap-2 text-xs">
                <span
                    v-if="deltaGood !== null && delta !== null"
                    :class="[
                        'inline-flex items-center gap-1 rounded-sm px-1.5 py-0.5 font-mono font-semibold',
                        deltaGood
                            ? 'bg-success-soft text-success-soft-foreground'
                            : 'bg-destructive-soft text-destructive-soft-foreground',
                    ]"
                >
                    <component
                        :is="delta >= 0 ? TrendingUp : TrendingDown"
                        class="size-3"
                        aria-hidden="true"
                    />
                    {{ fmt.percent(Math.abs(delta), 1) }}
                    <span class="sr-only">{{
                        delta >= 0 ? t('ui.kpi.increase') : t('ui.kpi.decrease')
                    }}</span>
                </span>
                <span v-if="hint" class="truncate text-muted-foreground">{{
                    hint
                }}</span>
            </div>
        </template>
    </article>
</template>
