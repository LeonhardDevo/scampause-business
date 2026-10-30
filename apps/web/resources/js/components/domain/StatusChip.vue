<script setup lang="ts">
import { computed } from 'vue';
import { STATUS_META, statusLabel } from '@/lib/domain/status';
import type { StatusTone } from '@/lib/domain/status';
import { cn } from '@/lib/utils';
import type { PaymentRequestStatus } from '@/types/domain';

const props = withDefaults(
    defineProps<{
        status: PaymentRequestStatus;
        size?: 'sm' | 'md';
        class?: string;
    }>(),
    { size: 'sm', class: '' },
);

const TONES: Record<StatusTone, string> = {
    neutral: 'bg-muted text-muted-foreground border-border',
    warning: 'bg-warning-soft text-warning-soft-foreground border-warning/30',
    info: 'bg-info-soft text-info-soft-foreground border-info/30',
    success: 'bg-success-soft text-success-soft-foreground border-success/30',
    muted: 'bg-muted text-muted-foreground border-border',
    destructive:
        'bg-destructive-soft text-destructive-soft-foreground border-destructive/30',
};

const meta = computed(() => STATUS_META[props.status]);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex shrink-0 items-center gap-1.5 rounded-sm border font-medium whitespace-nowrap',
                size === 'sm' ? 'h-6 px-2 text-xs' : 'h-7 px-2.5 text-sm',
                TONES[meta.tone],
                $props.class,
            )
        "
        data-test="status-chip"
        :data-status="status"
    >
        <component
            :is="meta.icon"
            :class="[
                size === 'sm' ? 'size-3.5' : 'size-4',
                meta.spinning ? 'animate-spin motion-reduce:animate-none' : '',
            ]"
            :stroke-width="2"
            aria-hidden="true"
        />
        {{ statusLabel(status) }}
    </span>
</template>
