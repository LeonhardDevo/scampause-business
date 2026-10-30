<script setup lang="ts">
import { Cpu, Globe, Server } from '@lucide/vue';
import { siTelegram } from 'simple-icons';
import { computed } from 'vue';
import { enumLabel } from '@/lib/domain/status';

const props = defineProps<{ channel: string | null | undefined }>();

const icon = computed(() => {
    switch (props.channel) {
        case 'system':
            return Server;
        case 'worker':
            return Cpu;
        default:
            return Globe;
    }
});

const label = computed(() =>
    props.channel === 'telegram'
        ? 'Telegram'
        : enumLabel('evidence_channel', props.channel),
);
</script>

<template>
    <span
        v-if="channel"
        class="inline-flex h-5 items-center gap-1 rounded-sm border bg-muted/60 px-1.5 text-[0.6875rem] font-medium text-muted-foreground"
        data-test="channel-badge"
    >
        <svg
            v-if="channel === 'telegram'"
            viewBox="0 0 24 24"
            class="size-3"
            fill="currentColor"
            aria-hidden="true"
        >
            <path :d="siTelegram.path" />
        </svg>
        <component
            :is="icon"
            v-else
            class="size-3"
            :stroke-width="2"
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>
