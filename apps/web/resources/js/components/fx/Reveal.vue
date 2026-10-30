<script setup lang="ts">
import { motion } from 'motion-v';
import { computed } from 'vue';
import { useReducedMotion } from '@/composables/useReducedMotion';

const props = withDefaults(
    defineProps<{
        delay?: number;
        duration?: number;
        y?: number;
        as?: 'div' | 'li' | 'section' | 'article';
    }>(),
    { delay: 0, duration: 0.32, y: 12, as: 'div' },
);

const reduced = useReducedMotion();

const initial = computed(() =>
    reduced.value ? { opacity: 1, y: 0 } : { opacity: 0, y: props.y },
);

const tag = computed(() => motion[props.as]);
</script>

<template>
    <component
        :is="tag"
        :initial="initial"
        :while-in-view="{ opacity: 1, y: 0 }"
        :in-view-options="{ once: true, margin: '0px 0px -8% 0px' }"
        :transition="{
            duration: reduced ? 0 : duration,
            delay: reduced ? 0 : delay,
            ease: [0.2, 0, 0, 1],
        }"
    >
        <slot />
    </component>
</template>
