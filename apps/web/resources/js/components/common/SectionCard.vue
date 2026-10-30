<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        title?: string | null;
        description?: string | null;
        icon?: Component;
        tone?: 'default' | 'danger';
        flush?: boolean;
        class?: string;
    }>(),
    {
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
                tone === 'danger' ? 'hazard-edge-l border-destructive/40' : '',
                $props.class,
            )
        "
    >
        <header
            v-if="title || $slots.actions"
            class="flex flex-col gap-3 border-b px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"
        >
            <div class="flex min-w-0 items-start gap-3">
                <span
                    v-if="icon"
                    :class="[
                        'mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-md border',
                        tone === 'danger'
                            ? 'bg-destructive-soft text-destructive-soft-foreground'
                            : 'bg-muted/60 text-primary',
                    ]"
                    aria-hidden="true"
                >
                    <component :is="icon" class="size-4" />
                </span>
                <div class="min-w-0">
                    <h2 class="font-display text-base font-semibold">
                        {{ title }}
                    </h2>
                    <p
                        v-if="description"
                        class="mt-0.5 text-sm text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>
        <div :class="flush ? '' : 'px-4 py-5 sm:px-5'">
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
