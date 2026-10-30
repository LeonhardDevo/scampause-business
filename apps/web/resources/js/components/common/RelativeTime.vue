<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { useStableNow } from '@/composables/useStableNow';
import { toDate } from '@/lib/format';

const props = defineProps<{ value: string | null | undefined }>();

const fmt = useFormat();
const now = useStableNow();
const date = computed(() => toDate(props.value));
</script>

<template>
    <time
        v-if="date"
        :datetime="date.toISOString()"
        :title="fmt.dateTime(date, 'long')"
        class="whitespace-nowrap"
    >
        {{ fmt.relative(date, now) }}
    </time>
    <span v-else class="text-muted-foreground">—</span>
</template>
