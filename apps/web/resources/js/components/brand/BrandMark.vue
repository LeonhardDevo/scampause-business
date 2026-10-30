<script setup lang="ts">
import type { SimpleIcon } from 'simple-icons';

defineProps<{ icon: SimpleIcon; label?: string; class?: string }>();
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        :class="$props.class"
        :role="label ? 'img' : undefined"
        :aria-label="label"
        :aria-hidden="label ? undefined : 'true'"
        focusable="false"
    >
        <path fill="currentColor" :d="icon.path" />
    </svg>
</template>
