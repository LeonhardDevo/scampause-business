<script setup lang="ts">
import { Check, CircleDashed, Loader, Minus, X } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { enumLabel } from '@/lib/domain/status';
import { t } from '@/lib/i18n';
import type {
    PipelineStage,
    PipelineState,
    PipelineStatus,
} from '@/types/domain';

const props = defineProps<{ pipeline: PipelineStatus }>();

const fmt = useFormat();

const ORDER: PipelineStage[] = ['extract', 'domain', 'bank', 'score'];

const ICONS: Record<PipelineState, Component> = {
    pending: CircleDashed,
    running: Loader,
    done: Check,
    failed: X,
    skipped: Minus,
};

const TONES: Record<PipelineState, string> = {
    pending: 'border-border bg-card text-muted-foreground',
    running: 'border-primary bg-primary/10 text-primary',
    done: 'border-success bg-success-soft text-success-soft-foreground',
    failed: 'border-destructive bg-destructive-soft text-destructive',
    skipped: 'border-border bg-muted text-muted-foreground',
};

const steps = computed(() =>
    ORDER.map((stage, index) => ({
        stage,
        index,
        ...(props.pipeline.stages[stage] ?? {
            state: 'pending' as PipelineState,
            at: null,
            error: null,
        }),
    })),
);

const current = computed(
    () =>
        steps.value.find((step) => step.state === 'running') ??
        steps.value.find((step) => step.state === 'pending') ??
        null,
);

const announcement = computed(() =>
    current.value
        ? t('scampause.pipeline.announce', {
              stage: enumLabel('pipeline_stage', current.value.stage),
              state: enumLabel('pipeline_state', current.value.state),
          })
        : t('scampause.pipeline.finished'),
);
</script>

<template>
    <div class="space-y-3" data-test="pipeline-stepper">
        <p class="sr-only" aria-live="polite">{{ announcement }}</p>
        <ol class="grid gap-3 sm:grid-cols-4">
            <li
                v-for="step in steps"
                :key="step.stage"
                class="relative flex items-start gap-3 rounded-lg border bg-card p-3"
                :data-stage="step.stage"
                :data-state="step.state"
                :aria-current="step.state === 'running' ? 'step' : undefined"
            >
                <span
                    :class="[
                        'inline-flex size-8 shrink-0 items-center justify-center rounded-full border-2',
                        TONES[step.state],
                    ]"
                    aria-hidden="true"
                >
                    <component
                        :is="ICONS[step.state]"
                        :class="[
                            'size-4',
                            step.state === 'running'
                                ? 'animate-spin motion-reduce:animate-none'
                                : '',
                        ]"
                        :stroke-width="2.25"
                    />
                </span>
                <div class="min-w-0">
                    <p class="font-mono text-[0.6875rem] text-muted-foreground">
                        {{ String(step.index + 1).padStart(2, '0') }}
                    </p>
                    <p class="text-sm leading-5 font-medium">
                        {{ enumLabel('pipeline_stage', step.stage) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ enumLabel('pipeline_state', step.state) }}
                        <template v-if="step.at && step.state === 'done'">
                            · {{ fmt.time(step.at) }}</template
                        >
                    </p>
                    <p
                        v-if="step.error"
                        class="mt-1 text-xs text-pretty text-destructive"
                    >
                        {{ step.error }}
                    </p>
                </div>
            </li>
        </ol>
    </div>
</template>
