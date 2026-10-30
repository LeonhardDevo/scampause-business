<script setup lang="ts">
import {
    Check,
    ChevronsUpDown,
    ClipboardList,
    FileCheck2,
    Stamp,
} from '@lucide/vue';
import { computed } from 'vue';
import Reveal from '@/components/fx/Reveal.vue';
import RiskBadge from '@/components/domain/RiskBadge.vue';
import SectionHeading from '@/components/marketing/SectionHeading.vue';
import { listIndices, t } from '@/lib/i18n';

const roles = [
    { key: 'prepare', icon: ClipboardList },
    { key: 'approve', icon: Stamp },
    { key: 'record', icon: FileCheck2 },
] as const;

const clients = computed(() =>
    listIndices('marketing.bookkeepers.clients', 'name').map((index) => ({
        name: t(`marketing.bookkeepers.clients.${index}.name`),
        meta: t(`marketing.bookkeepers.clients.${index}.meta`),
    })),
);

const queue = [
    { ref: 'SP-00042', level: 'critical', score: 92 },
    { ref: 'SP-00041', level: 'medium', score: 31 },
    { ref: 'SP-00039', level: 'low', score: 8 },
] as const;
</script>

<template>
    <section
        id="bookkeepers"
        class="scroll-mt-28 border-y bg-muted/30 px-4 py-16 sm:px-6 lg:py-24"
        aria-labelledby="bookkeepers-title"
    >
        <div
            class="mx-auto grid max-w-[75rem] items-center gap-12 lg:grid-cols-2 lg:gap-16"
        >
            <div class="space-y-8">
                <SectionHeading
                    id="bookkeepers-title"
                    :eyebrow="t('marketing.bookkeepers.eyebrow')"
                    :title="t('marketing.bookkeepers.title')"
                    :description="t('marketing.bookkeepers.subtitle')"
                />
                <ul class="space-y-4">
                    <Reveal
                        v-for="(role, index) in roles"
                        :key="role.key"
                        as="li"
                        :delay="index * 0.04"
                        class="flex gap-4"
                    >
                        <span
                            class="inline-flex size-10 shrink-0 items-center justify-center rounded-md border bg-card text-primary"
                        >
                            <component
                                :is="role.icon"
                                class="size-5"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                        </span>
                        <span>
                            <span class="block font-semibold">{{
                                t(
                                    `marketing.bookkeepers.roles.${role.key}.title`,
                                )
                            }}</span>
                            <span class="block text-sm text-muted-foreground">{{
                                t(
                                    `marketing.bookkeepers.roles.${role.key}.body`,
                                )
                            }}</span>
                        </span>
                    </Reveal>
                </ul>
            </div>

            <Reveal :delay="0.08">
                <div class="relative" aria-hidden="true">
                    <div
                        class="absolute -inset-8 bg-grid-paper mask-fade-center"
                    />
                    <div
                        class="dark relative overflow-hidden rounded-xl border bg-sidebar p-3 text-foreground shadow-lg"
                    >
                        <div
                            class="flex items-center gap-3 rounded-lg border px-3 py-2.5"
                        >
                            <span
                                class="inline-flex size-8 items-center justify-center rounded-md bg-muted font-mono text-sm font-semibold"
                                >{{ clients[0]?.name.charAt(0) }}</span
                            >
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-semibold"
                                    >{{ clients[0]?.name }}</span
                                >
                                <span
                                    class="block eyebrow text-[0.625rem] text-muted-foreground"
                                    >{{
                                        t('marketing.bookkeepers.switcher')
                                    }}</span
                                >
                            </span>
                            <ChevronsUpDown
                                class="size-4 text-muted-foreground"
                            />
                        </div>
                        <ul class="mt-2 space-y-0.5 rounded-lg bg-card p-1.5">
                            <li
                                v-for="(client, index) in clients"
                                :key="client.name"
                                class="flex items-center gap-3 rounded-md px-2.5 py-2"
                                :class="index === 0 ? 'bg-accent' : ''"
                            >
                                <span
                                    class="inline-flex size-7 items-center justify-center rounded-md border font-mono text-xs"
                                    >{{ client.name.charAt(0) }}</span
                                >
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm">{{
                                        client.name
                                    }}</span>
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ client.meta }}</span
                                    >
                                </span>
                                <Check
                                    v-if="index === 0"
                                    class="size-4 text-primary"
                                />
                            </li>
                        </ul>
                    </div>
                    <div
                        class="relative mx-6 -mt-4 rounded-xl border bg-card p-3 shadow-lg sm:mr-0 sm:ml-16"
                    >
                        <p class="px-1 pb-2 eyebrow text-muted-foreground">
                            {{ t('marketing.bookkeepers.queue') }}
                        </p>
                        <ul class="divide-y rounded-lg border">
                            <li
                                v-for="item in queue"
                                :key="item.ref"
                                class="flex items-center justify-between gap-3 px-3 py-2"
                            >
                                <span class="mono-data">{{ item.ref }}</span>
                                <RiskBadge
                                    :level="item.level"
                                    :score="item.score"
                                    :tooltip="false"
                                />
                            </li>
                        </ul>
                    </div>
                </div>
            </Reveal>
        </div>
    </section>
</template>
