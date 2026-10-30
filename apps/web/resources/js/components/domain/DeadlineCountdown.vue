<script setup lang="ts">
import { TimerReset } from '@lucide/vue';
import { useNow } from '@vueuse/core';
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import {
    DEADLINE_CLASSES,
    deadlineLabel,
    deadlineTone,
} from '@/lib/domain/deadline';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{ at: string | null | undefined; class?: string }>(),
    { class: '' },
);

const now = useNow({ interval: 30_000 });
const fmt = useFormat();
const tone = computed(() => deadlineTone(props.at, now.value));
const label = computed(() => deadlineLabel(props.at, now.value));
</script>

<template>
    <span
        v-if="at"
        :class="
            cn(
                'inline-flex items-center gap-1 text-xs font-medium whitespace-nowrap tabular-nums',
                DEADLINE_CLASSES[tone],
                $props.class,
            )
        "
        :title="fmt.dateTime(at)"
        data-test="deadline"
        :data-tone="tone"
    >
        <TimerReset class="size-3.5" :stroke-width="2" aria-hidden="true" />
        {{ label }}
    </span>
    <span v-else class="text-xs text-muted-foreground">—</span>
</template>
