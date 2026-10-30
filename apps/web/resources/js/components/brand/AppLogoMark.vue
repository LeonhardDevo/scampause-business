<script setup lang="ts">
import { useId } from 'reka-ui';
import type { HTMLAttributes } from 'vue';

withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        mono?: boolean;
        notch?: boolean;
    }>(),
    { class: undefined, mono: false, notch: true },
);

const uid = useId();
const clipId = `sp-mark-clip-${uid}`;
const maskId = `sp-mark-mask-${uid}`;

const bands = [
    '18,32 32,18 32,21 21,32',
    '24,32 32,24 32,27 27,32',
    '30,32 32,30 32,33 33,32',
];
</script>

<template>
    <svg
        viewBox="0 0 32 32"
        xmlns="http://www.w3.org/2000/svg"
        :class="$props.class"
        aria-hidden="true"
        focusable="false"
    >
        <defs>
            <clipPath :id="clipId">
                <rect width="32" height="32" rx="7" />
            </clipPath>
            <mask :id="maskId">
                <rect width="32" height="32" fill="white" />
                <rect x="10" y="9" width="4" height="14" rx="1" fill="black" />
                <rect x="18" y="9" width="4" height="14" rx="1" fill="black" />
                <template v-if="notch">
                    <polygon
                        v-for="band in bands"
                        :key="band"
                        :points="band"
                        fill="black"
                    />
                </template>
            </mask>
        </defs>
        <g v-if="mono" :clip-path="`url(#${clipId})`">
            <rect
                width="32"
                height="32"
                fill="currentColor"
                :mask="`url(#${maskId})`"
            />
        </g>
        <g v-else :clip-path="`url(#${clipId})`">
            <rect width="32" height="32" class="fill-hazard" />
            <rect
                x="10"
                y="9"
                width="4"
                height="14"
                rx="1"
                class="fill-hazard-foreground"
            />
            <rect
                x="18"
                y="9"
                width="4"
                height="14"
                rx="1"
                class="fill-hazard-foreground"
            />
            <template v-if="notch">
                <polygon
                    v-for="band in bands"
                    :key="band"
                    :points="band"
                    class="fill-hazard-foreground"
                />
            </template>
        </g>
    </svg>
</template>
