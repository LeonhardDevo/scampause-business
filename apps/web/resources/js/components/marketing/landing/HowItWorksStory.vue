<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import StoryPanel from '@/components/marketing/landing/StoryPanel.vue';
import SectionHeading from '@/components/marketing/SectionHeading.vue';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { StoryStep } from '@/types/brand';

const steps: StoryStep[] = ['forward', 'check', 'approve'];

const active = ref<StoryStep>('forward');
const markers = ref<HTMLElement[]>([]);
let observer: IntersectionObserver | null = null;

onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') {
        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    const step = (entry.target as HTMLElement).dataset.step;

                    if (
                        step === 'forward' ||
                        step === 'check' ||
                        step === 'approve'
                    ) {
                        active.value = step;
                    }
                }
            }
        },
        { rootMargin: '-45% 0px -45% 0px' },
    );

    markers.value.forEach((element) => observer?.observe(element));
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <section
        id="how-it-works"
        class="scroll-mt-28 border-y bg-muted/30 px-4 py-16 sm:px-6 lg:py-24"
        aria-labelledby="how-title"
    >
        <div class="mx-auto max-w-[75rem]">
            <SectionHeading
                id="how-title"
                :eyebrow="t('marketing.how.eyebrow')"
                :title="t('marketing.how.title')"
                :description="t('marketing.how.subtitle')"
            />

            <div
                class="mt-12 grid grid-cols-1 gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)] lg:gap-16"
            >
                <ol class="relative">
                    <li
                        v-for="(step, index) in steps"
                        :key="step"
                        ref="markers"
                        :data-step="step"
                        class="relative grid grid-cols-[2.5rem_minmax(0,1fr)] gap-x-4 pb-12 last:pb-0 lg:min-h-[26rem] lg:pb-0"
                        :aria-current="active === step ? 'step' : undefined"
                    >
                        <div class="relative flex flex-col items-center">
                            <span
                                :class="
                                    cn(
                                        'relative z-10 inline-flex size-10 items-center justify-center rounded-md border font-mono text-sm font-semibold transition-colors duration-200',
                                        active === step
                                            ? 'border-hazard bg-hazard text-hazard-foreground'
                                            : 'bg-card text-muted-foreground',
                                    )
                                "
                                aria-hidden="true"
                                >0{{ index + 1 }}</span
                            >
                            <span
                                v-if="index < steps.length - 1"
                                class="mt-2 w-1 flex-1 rounded-[1px] hazard-stripe opacity-80"
                                aria-hidden="true"
                            />
                        </div>
                        <div class="pt-1.5">
                            <p class="eyebrow text-muted-foreground">
                                {{ t(`marketing.how.steps.${step}.label`) }}
                            </p>
                            <h3
                                :class="
                                    cn(
                                        'mt-2 font-display text-2xl font-semibold transition-colors duration-200',
                                        active === step
                                            ? 'text-foreground'
                                            : 'lg:text-muted-foreground',
                                    )
                                "
                            >
                                {{ t(`marketing.how.steps.${step}.title`) }}
                            </h3>
                            <p
                                class="mt-2 max-w-md text-[1.0625rem] leading-relaxed text-pretty text-muted-foreground"
                            >
                                {{ t(`marketing.how.steps.${step}.body`) }}
                            </p>
                            <div class="mt-6 lg:hidden">
                                <StoryPanel :step="step" />
                            </div>
                        </div>
                    </li>
                </ol>

                <div class="hidden lg:block">
                    <div class="sticky top-32">
                        <div
                            class="absolute -inset-8 bg-grid-paper mask-fade-center"
                            aria-hidden="true"
                        />
                        <div class="relative">
                            <Transition
                                mode="out-in"
                                enter-active-class="transition-opacity duration-[220ms] ease-standard motion-reduce:transition-none"
                                enter-from-class="opacity-0"
                                leave-active-class="transition-opacity duration-150 ease-exit motion-reduce:transition-none"
                                leave-to-class="opacity-0"
                            >
                                <StoryPanel :key="active" :step="active" />
                            </Transition>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
