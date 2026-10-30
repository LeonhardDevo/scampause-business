<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        label: string;
        value: string | number | null;
        hint?: string | null;
        icon?: Component;
        tone?: 'default' | 'success' | 'warning' | 'info' | 'critical';
        href?: NonNullable<InertiaLinkProps['href']> | null;
        loading?: boolean;
        testId?: string | null;
    }>(),
    {
        hint: null,
        icon: undefined,
        tone: 'default',
        href: null,
        loading: false,
        testId: null,
    },
);

const VALUE_TONE = {
    default: 'text-foreground',
    success: 'text-success',
    warning: 'text-hazard-ink',
    info: 'text-info',
    critical: 'text-risk-critical',
} as const;
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href ?? undefined"
        :class="
            cn(
                'flex min-h-28 flex-col justify-between gap-3 rounded-xl border bg-card p-4 shadow-xs',
                href
                    ? 'cursor-pointer transition-colors hover:border-ring/40 hover:bg-accent/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring'
                    : '',
                tone === 'warning' ? 'border-warning/40' : '',
            )
        "
        :data-test="testId ?? undefined"
    >
        <div class="flex items-start justify-between gap-2">
            <p class="text-sm leading-5 text-pretty text-muted-foreground">
                {{ label }}
            </p>
            <component
                :is="icon"
                v-if="icon"
                class="size-4 shrink-0 text-muted-foreground"
                :stroke-width="1.75"
                aria-hidden="true"
            />
        </div>
        <div>
            <Skeleton v-if="loading" class="h-9 w-24" />
            <p
                v-else
                :class="[
                    'text-kpi leading-none tabular-nums',
                    VALUE_TONE[tone],
                ]"
            >
                {{ value ?? '—' }}
            </p>
            <p v-if="hint" class="mt-1.5 text-xs text-muted-foreground">
                {{ hint }}
            </p>
        </div>
    </component>
</template>
