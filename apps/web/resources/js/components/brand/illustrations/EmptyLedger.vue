<script setup lang="ts">
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        icon?: Component;
        tone?: 'primary' | 'ai' | 'warning' | 'info' | 'muted';
        size?: 'sm' | 'md';
    }>(),
    { icon: undefined, tone: 'primary', size: 'md' },
);

const tones = {
    primary: 'text-primary',
    ai: 'text-primary',
    warning: 'text-hazard-ink',
    info: 'text-info',
    muted: 'text-muted-foreground',
} as const;
</script>

<template>
    <div
        aria-hidden="true"
        :class="['relative mx-auto', size === 'sm' ? 'h-20 w-32' : 'h-28 w-44']"
    >
        <div
            class="absolute -inset-4 bg-grid-paper mask-fade-center opacity-90"
        />
        <svg
            viewBox="0 0 176 112"
            class="absolute inset-0 size-full"
            fill="none"
            stroke-width="1.5"
        >
            <rect
                x="20.75"
                y="12.75"
                width="134.5"
                height="86.5"
                rx="8"
                class="fill-card stroke-border"
            />
            <path d="M20.75 32.5h134.5" class="stroke-border" />
            <rect
                x="32"
                y="20"
                width="38"
                height="5"
                rx="1"
                class="fill-muted-foreground/40"
            />
            <rect
                x="32"
                y="44"
                width="62"
                height="6"
                rx="1.5"
                class="fill-redaction/15"
            />
            <rect
                x="32"
                y="58"
                width="48"
                height="6"
                rx="1.5"
                class="fill-redaction/15"
            />
            <rect
                x="32"
                y="72"
                width="70"
                height="6"
                rx="1.5"
                class="fill-redaction/15"
            />
            <rect
                x="120"
                y="44"
                width="24"
                height="24"
                rx="5"
                class="fill-hazard"
            />
            <rect
                x="127"
                y="50"
                width="3"
                height="12"
                rx="0.75"
                class="fill-hazard-foreground"
            />
            <rect
                x="134"
                y="50"
                width="3"
                height="12"
                rx="0.75"
                class="fill-hazard-foreground"
            />
            <path d="M120 82h24" class="stroke-border" stroke-dasharray="3 3" />
        </svg>
        <span
            v-if="icon"
            :class="[
                'absolute -right-1 -bottom-2 inline-flex items-center justify-center rounded-md border bg-card shadow-md',
                size === 'sm' ? 'size-8' : 'size-10',
                tones[tone],
            ]"
        >
            <component
                :is="icon"
                :class="size === 'sm' ? 'size-4' : 'size-5'"
                :stroke-width="1.75"
            />
        </span>
    </div>
</template>
