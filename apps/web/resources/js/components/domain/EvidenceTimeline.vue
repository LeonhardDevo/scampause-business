<script setup lang="ts">
import { Fingerprint, Link2, Mail, ScrollText } from '@lucide/vue';
import { siTelegram } from 'simple-icons';
import { computed } from 'vue';
import CopyButton from '@/components/common/CopyButton.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ChannelBadge from '@/components/domain/ChannelBadge.vue';
import { useFormat } from '@/composables/useFormat';
import { humanize, t, tOr } from '@/lib/i18n';
import type { DeliveryDto, TimelineItem } from '@/types/domain';

const props = withDefaults(
    defineProps<{
        items: TimelineItem[];
        deliveries?: DeliveryDto[];
    }>(),
    { deliveries: () => [] },
);

const fmt = useFormat();

const ordered = computed(() => [...props.items].sort((a, b) => b.seq - a.seq));

function nodeTone(event: string): string {
    if (/rejected|revoked|block_added|expired|denied/.test(event)) {
        return 'bg-destructive border-destructive';
    }

    if (/approved|verified|paid/.test(event)) {
        return 'bg-success border-success';
    }

    if (/verification|reminded|escalated/.test(event)) {
        return 'bg-hazard border-hazard';
    }

    return 'bg-card border-input';
}

function detailLabel(key: string): string {
    return tOr(`scampause.evidence_keys.${key}`, humanize(key));
}

function templateLabel(template: string): string {
    const name = template.replace(/^sp\./, '').replace(/[^a-z0-9_]/gi, '_');

    return tOr(
        `scampause.timeline.templates.${name}`,
        tOr(`domain.notifications.${name}.title`, humanize(name)),
    );
}

function detailValue(value: string | number | boolean | null): string {
    if (value === null) {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? t('scampause.common.yes') : t('scampause.common.no');
    }

    return String(value);
}
</script>

<template>
    <div class="space-y-6" data-test="evidence-timeline">
        <EmptyState
            v-if="!ordered.length"
            :icon="ScrollText"
            tone="muted"
            compact
            :title="t('scampause.timeline.empty')"
        />
        <ol v-else class="relative space-y-4 border-l pl-6">
            <li
                v-for="item in ordered"
                :key="item.id"
                class="relative"
                data-test="timeline-item"
                :data-event="item.event"
            >
                <span
                    :class="[
                        'absolute top-1.5 -left-[1.9rem] size-3 rounded-full border-2',
                        nodeTone(item.event),
                    ]"
                    aria-hidden="true"
                />
                <div
                    class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
                >
                    <p class="font-medium">{{ item.label }}</p>
                    <time
                        :datetime="item.occurred_at"
                        :title="new Date(item.occurred_at).toISOString()"
                        class="text-xs text-muted-foreground tabular-nums"
                        >{{ fmt.dateTime(item.occurred_at) }}</time
                    >
                </div>
                <div
                    class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                >
                    <span>{{
                        item.actor?.name ?? t('scampause.timeline.system')
                    }}</span>
                    <ChannelBadge :channel="item.channel" />
                    <span
                        class="inline-flex items-center gap-1 font-mono"
                        :title="item.hash"
                    >
                        <Fingerprint class="size-3" aria-hidden="true" />#{{
                            item.seq
                        }}
                        · {{ item.hash.slice(0, 10) }}…
                    </span>
                    <CopyButton
                        :value="item.hash"
                        :label="t('scampause.timeline.copy_hash')"
                    />
                </div>
                <dl
                    v-if="Object.keys(item.details).length"
                    class="mt-2 grid grid-cols-[minmax(0,10rem)_1fr] gap-x-3 gap-y-0.5 text-xs"
                >
                    <template v-for="(value, key) in item.details" :key="key">
                        <dt class="text-muted-foreground">
                            {{ detailLabel(String(key)) }}
                        </dt>
                        <dd class="break-words">{{ detailValue(value) }}</dd>
                    </template>
                </dl>
            </li>
        </ol>

        <section v-if="deliveries.length" class="space-y-2">
            <h3
                class="flex items-center gap-1.5 eyebrow text-[0.6875rem] text-muted-foreground"
            >
                <Link2 class="size-3.5" aria-hidden="true" />
                {{ t('scampause.timeline.deliveries') }}
            </h3>
            <ul class="divide-y rounded-lg border text-sm">
                <li
                    v-for="delivery in deliveries"
                    :key="delivery.id"
                    class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                >
                    <span class="flex items-center gap-2">
                        <svg
                            v-if="delivery.channel === 'telegram'"
                            viewBox="0 0 24 24"
                            class="size-3.5 text-muted-foreground"
                            fill="currentColor"
                            role="img"
                            aria-label="Telegram"
                        >
                            <path :d="siTelegram.path" />
                        </svg>
                        <Mail
                            v-else
                            class="size-3.5 text-muted-foreground"
                            :aria-label="t('scampause.timeline.email')"
                        />
                        {{ delivery.recipient?.name ?? '—' }}
                        <span class="text-muted-foreground">{{
                            templateLabel(delivery.template)
                        }}</span>
                    </span>
                    <span class="text-xs text-muted-foreground"
                        >{{
                            tOr(
                                `scampause.timeline.delivery_status.${delivery.status}`,
                                humanize(delivery.status),
                            )
                        }}
                        · {{ fmt.dateTime(delivery.at) }}</span
                    >
                </li>
            </ul>
        </section>
    </div>
</template>
