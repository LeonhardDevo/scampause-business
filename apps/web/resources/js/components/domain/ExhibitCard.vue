<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        eyebrow?: string | null;
        title?: string | null;
        description?: string | null;
        icon?: Component;
        tone?: 'default' | 'critical' | 'warning';
        flush?: boolean;
        class?: string;
    }>(),
    {
        eyebrow: null,
        title: null,
        description: null,
        icon: undefined,
        tone: 'default',
        flush: false,
        class: '',
    },
);
</script>

<template>
    <section
        :class="
            cn(
                'overflow-hidden rounded-xl border bg-card text-card-foreground shadow-xs',
                tone === 'critical'
                    ? 'hazard-edge-l border-destructive/40'
                    : '',
                tone === 'warning' ? 'border-warning/40' : '',
                $props.class,
            )
        "
    >
        <header
            v-if="eyebrow || title || $slots.actions"
            class="flex flex-col gap-3 border-b px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5"
        >
            <div class="flex min-w-0 items-start gap-3">
                <component
                    :is="icon"
                    v-if="icon"
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    :stroke-width="1.75"
                    aria-hidden="true"
                />
                <div class="min-w-0 space-y-0.5">
                    <p
                        v-if="eyebrow"
                        class="eyebrow text-[0.6875rem] text-muted-foreground"
                    >
                        {{ eyebrow }}
                    </p>
                    <h2
                        v-if="title"
                        class="font-display text-base leading-6 font-semibold"
                    >
                        {{ title }}
                    </h2>
                    <p
                        v-if="description"
                        class="text-sm text-pretty text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>
            </div>
            <div
                v-if="$slots.actions"
                class="flex shrink-0 flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </header>
        <div :class="flush ? '' : 'px-4 py-4 sm:px-5'">
            <slot />
        </div>
        <footer
            v-if="$slots.footer"
            class="flex flex-col-reverse gap-2 border-t bg-muted/40 px-4 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-5"
        >
            <slot name="footer" />
        </footer>
    </section>
</template>
