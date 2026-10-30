<script setup lang="ts">
import { ChevronDown, CircleDot, Lightbulb } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import { enumLabel } from '@/lib/domain/status';
import {
    CATEGORY_ICONS,
    evidenceEntries,
    floorLevel,
    groupSignals,
} from '@/lib/domain/signals';
import { humanize, t, tOr } from '@/lib/i18n';
import { severityBars } from '@/lib/risk';
import type { RiskSignal } from '@/types/domain';

const props = withDefaults(
    defineProps<{
        signals: RiskSignal[];
        floorSignal?: string | null;
        compact?: boolean;
    }>(),
    { floorSignal: null, compact: false },
);

const groups = computed(() => groupSignals(props.signals));
const open = ref<Set<string>>(new Set());

function toggle(code: string): void {
    const next = new Set(open.value);

    if (next.has(code)) {
        next.delete(code);
    } else {
        next.add(code);
    }

    open.value = next;
}

const SEVERITY_BAR: Record<string, string> = {
    critical: 'bg-risk-critical-fill',
    high: 'bg-risk-high-fill',
    medium: 'bg-risk-medium-fill',
    low: 'bg-risk-low-fill',
    info: 'bg-muted-foreground',
};

const floorTitle = computed(() => {
    if (!props.floorSignal) {
        return null;
    }

    return (
        props.signals.find((signal) => signal.code === props.floorSignal)
            ?.title ?? humanize(props.floorSignal)
    );
});

function evidenceKey(key: string): string {
    return tOr(`scampause.evidence_keys.${key}`, humanize(key));
}
</script>

<template>
    <div class="space-y-5" data-test="signal-list">
        <p
            v-if="floorTitle"
            class="flex items-start gap-2 rounded-md border border-destructive/30 bg-risk-critical-tint px-3 py-2 text-sm text-risk-critical"
            data-test="floor-signal"
        >
            {{
                t('scampause.signals.floor', {
                    signal: floorTitle,
                    level: enumLabel(
                        'risk_level',
                        floorLevel(floorSignal) ?? 'high',
                    ),
                })
            }}
        </p>
        <EmptyState
            v-if="!groups.length"
            :icon="CircleDot"
            tone="muted"
            compact
            :title="t('scampause.signals.none_title')"
            :description="t('scampause.signals.none_body')"
        />
        <section
            v-for="group in groups"
            :key="group.category"
            :aria-labelledby="`signal-group-${group.category}`"
            class="space-y-2"
        >
            <h3
                :id="`signal-group-${group.category}`"
                class="flex items-center gap-2 eyebrow text-[0.6875rem] text-muted-foreground"
            >
                <component
                    :is="CATEGORY_ICONS[group.category]"
                    class="size-3.5"
                    :stroke-width="2"
                    aria-hidden="true"
                />
                {{ enumLabel('signal_category', group.category) }}
                <span class="font-mono tabular-nums">+{{ group.points }}</span>
            </h3>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="signal in group.signals"
                    :key="signal.code"
                    class="px-3 py-3"
                    data-test="signal"
                    :data-code="signal.code"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-1 flex items-end gap-px"
                            role="img"
                            :aria-label="
                                t('ui.risk.severity', {
                                    bars: severityBars(signal.points),
                                    max: 4,
                                })
                            "
                        >
                            <span
                                v-for="bar in 4"
                                :key="bar"
                                :class="[
                                    'w-1 rounded-[1px]',
                                    bar <= severityBars(signal.points)
                                        ? SEVERITY_BAR[signal.severity]
                                        : 'bg-muted',
                                ]"
                                :style="{ height: `${5 + bar * 3}px` }"
                            />
                        </span>
                        <div class="min-w-0 flex-1 space-y-1">
                            <div
                                class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
                            >
                                <p class="font-medium">{{ signal.title }}</p>
                                <span
                                    class="font-mono text-xs text-muted-foreground tabular-nums"
                                    >+{{ signal.points }}
                                    {{ t('scampause.signals.points') }}</span
                                >
                            </div>
                            <p
                                v-if="!compact"
                                class="text-sm text-pretty text-muted-foreground"
                            >
                                {{ signal.explanation }}
                            </p>
                            <p
                                v-if="signal.advice && !compact"
                                class="flex items-start gap-1.5 text-sm text-pretty"
                            >
                                <Lightbulb
                                    class="mt-0.5 size-4 shrink-0 text-hazard-ink"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                {{ signal.advice }}
                            </p>
                            <template
                                v-if="
                                    !compact && evidenceEntries(signal).length
                                "
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-sm text-xs font-medium text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    :aria-expanded="open.has(signal.code)"
                                    @click="toggle(signal.code)"
                                >
                                    <ChevronDown
                                        :class="[
                                            'size-3.5 transition-transform',
                                            open.has(signal.code)
                                                ? 'rotate-180'
                                                : '',
                                        ]"
                                        aria-hidden="true"
                                    />
                                    {{ t('scampause.signals.evidence') }}
                                </button>
                                <dl
                                    v-if="open.has(signal.code)"
                                    class="grid grid-cols-[minmax(0,10rem)_1fr] gap-x-3 gap-y-1 rounded-md bg-muted/50 p-2 text-xs"
                                >
                                    <template
                                        v-for="entry in evidenceEntries(signal)"
                                        :key="entry.key"
                                    >
                                        <dt class="text-muted-foreground">
                                            {{ evidenceKey(entry.key) }}
                                        </dt>
                                        <dd class="font-mono break-all">
                                            {{ entry.value }}
                                        </dd>
                                    </template>
                                </dl>
                            </template>
                        </div>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
