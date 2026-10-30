<script setup lang="ts">
import { siTelegram } from 'simple-icons';
import BrandMark from '@/components/brand/BrandMark.vue';

defineProps<{ class?: string; label?: string }>();
</script>

<template>
    <BrandMark :icon="siTelegram" :label="label" :class="$props.class" />
</template>
