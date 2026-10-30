<script setup lang="ts">
import Reveal from '@/components/fx/Reveal.vue';

defineProps<{ title: string; description?: string | null; id?: string }>();
</script>

<template>
    <section class="px-4 py-16 sm:px-6 lg:py-24" :aria-labelledby="id">
        <Reveal>
            <div
                class="dark hazard-edge-t relative mx-auto max-w-[75rem] overflow-hidden rounded-xl border bg-sidebar px-6 pt-16 pb-14 text-foreground sm:px-12"
            >
                <div
                    class="pointer-events-none absolute inset-0 bg-signal-glow"
                    aria-hidden="true"
                />
                <div
                    class="pointer-events-none absolute inset-0 bg-grid-paper mask-fade-top opacity-60"
                    aria-hidden="true"
                />
                <div
                    class="relative grid items-end gap-8 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]"
                >
                    <div class="space-y-4">
                        <h2
                            :id="id"
                            class="max-w-2xl text-display text-balance"
                        >
                            {{ title }}
                        </h2>
                        <p
                            v-if="description"
                            class="max-w-xl text-[1.0625rem] leading-relaxed text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row lg:justify-end">
                        <slot />
                    </div>
                </div>
            </div>
        </Reveal>
    </section>
</template>
