<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    FileLock2,
    Fingerprint,
    Landmark,
    Link2,
    MessagesSquare,
    Network,
    ShieldAlert,
    ShieldQuestion,
} from '@lucide/vue';
import { computed } from 'vue';
import Reveal from '@/components/fx/Reveal.vue';
import CtaBand from '@/components/marketing/CtaBand.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { endpoints } from '@/lib/endpoints';
import { listIndices, t } from '@/lib/i18n';

const pillars = [
    { key: 'data', icon: FileLock2 },
    { key: 'access', icon: Fingerprint },
    { key: 'payments', icon: Landmark },
    { key: 'ai', icon: MessagesSquare },
    { key: 'infra', icon: Network },
    { key: 'audit', icon: Link2 },
] as const;

const sections = computed(() =>
    pillars.map((pillar, index) => ({
        ...pillar,
        code: String.fromCharCode(65 + index),
        points: listIndices(
            `marketing.security_page.pillars.${pillar.key}.points`,
            'text',
        ).map((point) =>
            t(
                `marketing.security_page.pillars.${pillar.key}.points.${point}.text`,
            ),
        ),
    })),
);
</script>

<template>
    <SeoHead
        :title="t('marketing.security_page.meta_title')"
        :description="t('marketing.security_page.meta_description')"
    />

    <section class="relative overflow-hidden" aria-labelledby="security-hero">
        <div
            class="pointer-events-none absolute inset-0 bg-grid-paper mask-fade-top"
            aria-hidden="true"
        />
        <div
            class="pointer-events-none absolute inset-0 bg-signal-glow"
            aria-hidden="true"
        />
        <div
            class="relative mx-auto max-w-[75rem] px-4 pt-32 pb-14 sm:px-6 sm:pt-36"
        >
            <div class="max-w-3xl space-y-4 motion-safe:animate-fade-up">
                <p
                    class="flex items-center gap-2.5 eyebrow text-muted-foreground"
                >
                    <span
                        class="inline-block h-3 w-0.5 bg-hazard"
                        aria-hidden="true"
                    />
                    {{ t('marketing.security_page.eyebrow') }}
                </p>
                <h1 id="security-hero" class="text-display-xl text-balance">
                    {{ t('marketing.security_page.title') }}
                </h1>
                <p
                    class="max-w-2xl text-[1.0625rem] leading-relaxed text-pretty text-muted-foreground"
                >
                    {{ t('marketing.security_page.subtitle') }}
                </p>
            </div>
        </div>
    </section>

    <section
        class="mx-auto max-w-[75rem] px-4 pb-16 sm:px-6"
        :aria-label="t('marketing.security_page.pillars_label')"
    >
        <ol class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <Reveal
                v-for="(section, index) in sections"
                :key="section.key"
                as="li"
                :delay="(index % 3) * 0.04"
                class="flex flex-col rounded-xl border bg-card p-6 shadow-xs"
            >
                <div class="flex items-start justify-between gap-4">
                    <span
                        class="inline-flex size-10 items-center justify-center rounded-md border bg-background text-primary"
                    >
                        <component
                            :is="section.icon"
                            class="size-5"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                    </span>
                    <span
                        class="rounded-sm border px-1.5 py-0.5 eyebrow text-[0.6875rem] text-muted-foreground"
                        aria-hidden="true"
                        >{{ section.code }}</span
                    >
                </div>
                <h2 class="mt-5 font-display text-xl font-semibold">
                    {{
                        t(
                            `marketing.security_page.pillars.${section.key}.title`,
                        )
                    }}
                </h2>
                <p class="mt-1.5 text-sm text-pretty text-muted-foreground">
                    {{
                        t(`marketing.security_page.pillars.${section.key}.body`)
                    }}
                </p>
                <ul
                    class="mt-5 space-y-2.5 border-t border-dashed pt-5 text-sm"
                >
                    <li
                        v-for="point in section.points"
                        :key="point"
                        class="flex gap-2.5"
                    >
                        <Check
                            class="mt-0.5 size-4 shrink-0 text-success"
                            aria-hidden="true"
                        />
                        <span>{{ point }}</span>
                    </li>
                </ul>
            </Reveal>
        </ol>
    </section>

    <section
        class="mx-auto grid max-w-[75rem] gap-4 px-4 pb-8 sm:px-6 lg:grid-cols-2"
    >
        <article
            id="risk-disclaimer"
            class="hazard-edge-l scroll-mt-28 rounded-xl border bg-card py-6 pr-6 pl-8 shadow-xs"
            aria-labelledby="risk-disclaimer-title"
        >
            <ShieldAlert
                class="size-6 text-hazard-ink"
                :stroke-width="1.75"
                aria-hidden="true"
            />
            <h2
                id="risk-disclaimer-title"
                class="mt-4 font-display text-xl font-semibold"
            >
                {{ t('marketing.security_page.disclaimer_title') }}
            </h2>
            <p
                class="mt-2 text-sm leading-relaxed text-pretty text-muted-foreground"
            >
                {{ t('marketing.security_page.disclaimer_body') }}
            </p>
        </article>
        <article
            class="rounded-xl border bg-card p-6 shadow-xs"
            aria-labelledby="disclosure-title"
        >
            <ShieldQuestion
                class="size-6 text-primary"
                :stroke-width="1.75"
                aria-hidden="true"
            />
            <h2
                id="disclosure-title"
                class="mt-4 font-display text-xl font-semibold"
            >
                {{ t('marketing.security_page.disclosure_title') }}
            </h2>
            <p
                class="mt-2 text-sm leading-relaxed text-pretty text-muted-foreground"
            >
                {{ t('marketing.security_page.disclosure_body') }}
            </p>
            <Link
                :href="endpoints.legal('privacy')"
                class="mt-4 inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-primary hover:underline hover:underline-offset-4"
            >
                {{ t('marketing.footer.privacy') }}
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </article>
    </section>

    <CtaBand
        id="security-final-cta"
        :title="t('marketing.final_cta.title')"
        :description="t('marketing.final_cta.subtitle')"
    >
        <Button as-child size="lg">
            <Link :href="endpoints.pricing()">
                {{ t('marketing.final_cta.secondary') }}
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </Button>
    </CtaBand>
</template>
