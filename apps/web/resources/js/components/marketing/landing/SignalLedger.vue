<script setup lang="ts">
import { ShieldAlert } from '@lucide/vue';
import { computed } from 'vue';
import Reveal from '@/components/fx/Reveal.vue';
import SectionHeading from '@/components/marketing/SectionHeading.vue';
import { listIndices, t } from '@/lib/i18n';

const rows = computed(() =>
    listIndices('marketing.ledger.rows', 'code').map((index) => ({
        code: t(`marketing.ledger.rows.${index}.code`),
        signal: t(`marketing.ledger.rows.${index}.signal`),
        why: t(`marketing.ledger.rows.${index}.why`),
        points: t(`marketing.ledger.rows.${index}.points`),
    })),
);
</script>

<template>
    <section
        id="signals"
        class="scroll-mt-28 px-4 py-16 sm:px-6 lg:py-24"
        aria-labelledby="ledger-title"
    >
        <div
            class="mx-auto grid max-w-[75rem] gap-10 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)] lg:gap-14"
        >
            <div class="space-y-8">
                <SectionHeading
                    id="ledger-title"
                    :eyebrow="t('marketing.ledger.eyebrow')"
                    :title="t('marketing.ledger.title')"
                    :description="t('marketing.ledger.subtitle')"
                />
                <Reveal>
                    <blockquote
                        class="rounded-lg border border-l-4 border-l-hazard bg-card py-4 pr-4 pl-5 text-[1.0625rem] leading-relaxed font-medium text-pretty"
                    >
                        <ShieldAlert
                            class="mb-2 size-5 text-hazard-ink"
                            aria-hidden="true"
                        />
                        {{ t('marketing.ledger.statement') }}
                    </blockquote>
                </Reveal>
            </div>

            <Reveal :delay="0.04">
                <div
                    class="overflow-hidden rounded-xl border bg-card bg-grid-paper"
                >
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">
                            {{
                                t('marketing.ledger.title')
                            }}
                        </caption>
                        <thead class="border-b bg-card">
                            <tr>
                                <th
                                    scope="col"
                                    class="hidden px-4 py-3 eyebrow text-[0.6875rem] text-muted-foreground sm:table-cell"
                                >
                                    {{ t('marketing.ledger.columns.code') }}
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 eyebrow text-[0.6875rem] text-muted-foreground"
                                >
                                    {{ t('marketing.ledger.columns.signal') }}
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-right eyebrow text-[0.6875rem] text-muted-foreground"
                                >
                                    {{ t('marketing.ledger.columns.points') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-dashed">
                            <tr
                                v-for="row in rows"
                                :key="row.code"
                                class="bg-card/80 align-top"
                            >
                                <td
                                    class="hidden px-4 py-3.5 font-mono text-xs text-muted-foreground sm:table-cell"
                                >
                                    {{ row.code }}
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="block font-medium">{{
                                        row.signal
                                    }}</span>
                                    <span
                                        class="mt-0.5 block text-xs text-muted-foreground"
                                        >{{ row.why }}</span
                                    >
                                    <span
                                        class="mt-1 block font-mono text-[0.6875rem] text-muted-foreground sm:hidden"
                                        >{{ row.code }}</span
                                    >
                                </td>
                                <td
                                    class="px-4 py-3.5 text-right font-mono font-semibold tabular-nums"
                                >
                                    +{{ row.points }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Reveal>
        </div>
    </section>
</template>
