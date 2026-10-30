<script setup lang="ts">
import { BookOpenCheck } from '@lucide/vue';
import ScamGlyph from '@/components/brand/illustrations/ScamGlyph.vue';
import type { ScamKind } from '@/types/brand';
import Reveal from '@/components/fx/Reveal.vue';
import SectionHeading from '@/components/marketing/SectionHeading.vue';
import { t } from '@/lib/i18n';

const scams: ScamKind[] = ['invoice', 'vendor', 'payroll', 'ceo'];
</script>

<template>
    <section
        id="scams"
        class="scroll-mt-28 px-4 py-16 sm:px-6 lg:py-24"
        aria-labelledby="scams-title"
    >
        <div class="mx-auto max-w-[75rem]">
            <SectionHeading
                id="scams-title"
                :eyebrow="t('marketing.scams.eyebrow')"
                :title="t('marketing.scams.title')"
                :description="t('marketing.scams.subtitle')"
            />
            <ul
                class="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
            >
                <Reveal
                    v-for="(scam, index) in scams"
                    :key="scam"
                    as="li"
                    :delay="index * 0.04"
                    class="flex flex-col overflow-hidden rounded-xl border bg-card shadow-xs"
                >
                    <div
                        class="relative flex h-36 items-center justify-center border-b bg-muted/40 bg-grid-paper"
                    >
                        <ScamGlyph :kind="scam" class="h-24 w-auto" />
                        <span
                            class="absolute top-3 left-3 rounded-sm border bg-card px-1.5 py-0.5 eyebrow text-[0.6875rem] text-muted-foreground"
                            >0{{ index + 1 }}</span
                        >
                    </div>
                    <div class="flex flex-1 flex-col gap-2 p-5">
                        <h3 class="font-display text-lg font-semibold">
                            {{ t(`marketing.scams.items.${scam}.title`) }}
                        </h3>
                        <p
                            class="text-sm leading-relaxed text-pretty text-muted-foreground italic"
                        >
                            {{ t(`marketing.scams.items.${scam}.example`) }}
                        </p>
                    </div>
                </Reveal>
            </ul>
            <p
                class="mt-6 flex items-start gap-2 text-sm text-muted-foreground"
            >
                <BookOpenCheck
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                {{ t('marketing.scams.source') }}
            </p>
        </div>
    </section>
</template>
