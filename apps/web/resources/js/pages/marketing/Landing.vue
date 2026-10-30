<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Ban,
    ChevronRight,
    EyeOff,
    Landmark,
    Languages,
    Link2,
    MapPin,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import IntervalToggle from '@/components/billing/IntervalToggle.vue';
import PlanCard from '@/components/billing/PlanCard.vue';
import Reveal from '@/components/fx/Reveal.vue';
import CtaBand from '@/components/marketing/CtaBand.vue';
import FaqList from '@/components/marketing/FaqList.vue';
import BookkeepersPanel from '@/components/marketing/landing/BookkeepersPanel.vue';
import FeatureBento from '@/components/marketing/landing/FeatureBento.vue';
import HeroScanMockup from '@/components/marketing/landing/HeroScanMockup.vue';
import HowItWorksStory from '@/components/marketing/landing/HowItWorksStory.vue';
import ScamCards from '@/components/marketing/landing/ScamCards.vue';
import SignalLedger from '@/components/marketing/landing/SignalLedger.vue';
import SectionHeading from '@/components/marketing/SectionHeading.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { maxYearlySavings } from '@/lib/billing';
import { endpoints } from '@/lib/endpoints';
import { listIndices, t } from '@/lib/i18n';
import { register } from '@/routes';
import type { BillingInterval, Plan } from '@/types';

const props = withDefaults(
    defineProps<{ canRegister?: boolean; plans?: Plan[] }>(),
    {
        canRegister: true,
        plans: () => [],
    },
);

const page = usePage();
const authed = computed(() => Boolean(page.props.auth?.user));

const primaryCta = computed(() =>
    authed.value
        ? {
              href: endpoints.dashboard().url,
              label: t('marketing.nav.dashboard'),
              test: 'hero-cta',
          }
        : props.canRegister
          ? {
                href: register().url,
                label: t('marketing.hero.cta_primary'),
                test: 'cta-register',
            }
          : {
                href: endpoints.pricing().url,
                label: t('marketing.hero.cta_primary'),
                test: 'hero-cta',
            },
);

const micro = [
    { key: 'bank', icon: Landmark },
    { key: 'languages', icon: Languages },
    { key: 'money', icon: Ban },
] as const;

const securityItems = [
    { key: 'encryption', icon: EyeOff },
    { key: 'trackers', icon: Ban },
    { key: 'chain', icon: Link2 },
    { key: 'residency', icon: MapPin },
] as const;

const faq = computed(() =>
    listIndices('marketing.faq.items', 'q').map((index) => ({
        id: `faq-${index}`,
        question: t(`marketing.faq.items.${index}.q`),
        answer: t(`marketing.faq.items.${index}.a`),
    })),
);

const interval = ref<BillingInterval>('year');
</script>

<template>
    <SeoHead
        :title="t('marketing.meta.title')"
        :description="t('marketing.meta.description')"
    />

    <section
        class="relative overflow-hidden"
        aria-labelledby="hero-title"
        data-test="landing-hero"
    >
        <div
            class="pointer-events-none absolute inset-0 bg-grid-paper mask-fade-top"
            aria-hidden="true"
        />
        <div
            class="pointer-events-none absolute inset-0 bg-signal-glow"
            aria-hidden="true"
        />
        <div
            class="relative mx-auto grid max-w-[75rem] grid-cols-1 items-center gap-12 px-4 pt-32 pb-24 sm:px-6 sm:pt-36 lg:grid-cols-12 lg:gap-10 lg:pb-32"
        >
            <div class="space-y-7 lg:col-span-7">
                <p
                    class="flex items-center gap-2.5 eyebrow text-muted-foreground motion-safe:animate-fade-up"
                >
                    <span
                        class="inline-block h-3 w-0.5 bg-hazard"
                        aria-hidden="true"
                    />
                    {{ t('marketing.hero.eyebrow') }}
                </p>
                <h1
                    id="hero-title"
                    class="text-display-xl text-balance motion-safe:animate-fade-up"
                    style="animation-delay: 40ms"
                >
                    <span class="block">{{ t('marketing.hero.title') }}</span>
                    <span class="block text-muted-foreground">{{
                        t('marketing.hero.title_tail')
                    }}</span>
                </h1>
                <p
                    class="max-w-xl text-[1.0625rem] leading-[1.6] text-pretty text-muted-foreground motion-safe:animate-fade-up sm:text-lg"
                    style="animation-delay: 80ms"
                >
                    {{ t('marketing.hero.subtitle') }}
                </p>
                <div
                    class="flex flex-col gap-3 motion-safe:animate-fade-up sm:flex-row"
                    style="animation-delay: 120ms"
                >
                    <Button as-child size="lg">
                        <Link
                            :href="primaryCta.href"
                            :data-test="primaryCta.test"
                        >
                            {{ primaryCta.label }}
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                    <Button as-child size="lg" variant="outline">
                        <a href="#how-it-works">
                            {{ t('marketing.hero.cta_secondary') }}
                            <ChevronRight class="size-4" aria-hidden="true" />
                        </a>
                    </Button>
                </div>
                <ul
                    class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-muted-foreground motion-safe:animate-fade-up"
                    style="animation-delay: 160ms"
                >
                    <li
                        v-for="item in micro"
                        :key="item.key"
                        class="flex items-center gap-1.5"
                    >
                        <component
                            :is="item.icon"
                            class="size-4"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        {{ t(`marketing.hero.micro.${item.key}`) }}
                    </li>
                </ul>
            </div>
            <div class="pb-6 sm:pb-16 lg:col-span-5 lg:pb-0">
                <HeroScanMockup />
            </div>
        </div>
    </section>

    <ScamCards />

    <HowItWorksStory />

    <FeatureBento />

    <SignalLedger />

    <BookkeepersPanel />

    <section
        id="security"
        class="scroll-mt-28 px-4 py-16 sm:px-6 lg:py-24"
        aria-labelledby="security-title"
    >
        <div
            class="mx-auto grid max-w-[75rem] gap-10 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] lg:gap-16"
        >
            <div class="space-y-6">
                <SectionHeading
                    id="security-title"
                    :eyebrow="t('marketing.security.eyebrow')"
                    :title="t('marketing.security.title')"
                    :description="t('marketing.security.subtitle')"
                />
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    <Link
                        :href="endpoints.security()"
                        class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-primary hover:underline hover:underline-offset-4"
                    >
                        {{ t('marketing.security.cta') }}
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                    <Link
                        href="/security#risk-disclaimer"
                        class="inline-flex min-h-11 items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground hover:underline hover:underline-offset-4"
                    >
                        {{ t('marketing.security.disclaimer') }}
                    </Link>
                </div>
            </div>
            <ul
                class="grid grid-cols-1 gap-px overflow-hidden rounded-xl border bg-border sm:grid-cols-2"
            >
                <Reveal
                    v-for="(item, index) in securityItems"
                    :key="item.key"
                    as="li"
                    :delay="index * 0.04"
                    class="bg-card p-5"
                >
                    <component
                        :is="item.icon"
                        class="size-5 text-primary"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    <h3 class="mt-3 font-semibold">
                        {{ t(`marketing.security.items.${item.key}.title`) }}
                    </h3>
                    <p
                        class="mt-1 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ t(`marketing.security.items.${item.key}.body`) }}
                    </p>
                </Reveal>
            </ul>
        </div>
    </section>

    <section
        id="pricing"
        class="scroll-mt-28 border-y bg-muted/30 px-4 py-16 sm:px-6 lg:py-24"
        aria-labelledby="pricing-title"
    >
        <div class="mx-auto max-w-[75rem]">
            <div
                class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between"
            >
                <SectionHeading
                    id="pricing-title"
                    :eyebrow="t('marketing.pricing_teaser.eyebrow')"
                    :title="t('marketing.pricing_teaser.title')"
                    :description="t('marketing.pricing_teaser.subtitle')"
                />
                <IntervalToggle
                    v-if="plans.length"
                    v-model="interval"
                    :savings="maxYearlySavings(plans)"
                />
            </div>
            <div
                v-if="plans.length"
                class="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
            >
                <PlanCard
                    v-for="plan in plans"
                    :key="plan.key"
                    :plan="plan"
                    :interval="interval"
                    :class="plan.highlighted ? 'order-first sm:order-none' : ''"
                >
                    <template #cta>
                        <Button
                            as-child
                            class="mt-6 w-full"
                            :variant="plan.highlighted ? 'default' : 'outline'"
                        >
                            <Link
                                :href="
                                    canRegister
                                        ? register()
                                        : endpoints.pricing()
                                "
                                >{{ t('ui.billing.start_free') }}</Link
                            >
                        </Button>
                    </template>
                </PlanCard>
            </div>
            <div
                class="mt-8 flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p
                    class="flex items-center gap-2 text-sm font-medium text-foreground"
                >
                    <span
                        class="inline-block h-3 w-0.5 bg-hazard"
                        aria-hidden="true"
                    />
                    {{ t('marketing.pricing_teaser.safety') }}
                </p>
                <Button as-child variant="outline">
                    <Link :href="endpoints.pricing()">
                        {{ t('marketing.pricing_teaser.cta') }}
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </Button>
            </div>
        </div>
    </section>

    <section
        id="faq"
        class="scroll-mt-28 px-4 py-16 sm:px-6 lg:py-24"
        aria-labelledby="faq-title"
    >
        <div
            class="mx-auto grid max-w-[75rem] gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:gap-16"
        >
            <SectionHeading
                id="faq-title"
                :eyebrow="t('marketing.faq.eyebrow')"
                :title="t('marketing.faq.title')"
            />
            <Reveal>
                <FaqList :items="faq" />
            </Reveal>
        </div>
    </section>

    <CtaBand
        id="final-cta-title"
        :title="t('marketing.final_cta.title')"
        :description="t('marketing.final_cta.subtitle')"
    >
        <Button as-child size="lg">
            <Link
                :href="primaryCta.href"
                :data-test="
                    primaryCta.test === 'cta-register'
                        ? 'final-cta-register'
                        : 'final-cta'
                "
            >
                {{ primaryCta.label }}
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </Button>
        <Button as-child size="lg" variant="outline">
            <Link :href="endpoints.pricing()">
                {{ t('marketing.final_cta.secondary') }}
            </Link>
        </Button>
    </CtaBand>
</template>
