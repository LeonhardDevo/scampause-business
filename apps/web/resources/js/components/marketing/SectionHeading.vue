<script setup lang="ts">
import Reveal from '@/components/fx/Reveal.vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        eyebrow?: string | null;
        title: string;
        description?: string | null;
        align?: 'center' | 'left';
        id?: string;
        index?: string | null;
    }>(),
    {
        eyebrow: null,
        description: null,
        align: 'left',
        id: undefined,
        index: null,
    },
);
</script>

<template>
    <Reveal>
        <div
            :class="
                cn(
                    'max-w-3xl space-y-4',
                    align === 'center' ? 'mx-auto text-center' : '',
                )
            "
        >
            <p
                v-if="eyebrow"
                :class="
                    cn(
                        'flex items-center gap-2.5 eyebrow text-muted-foreground',
                        align === 'center' ? 'justify-center' : '',
                    )
                "
            >
                <span
                    v-if="index"
                    class="rounded-sm border px-1.5 py-0.5 text-foreground"
                    >{{ index }}</span
                >
                <span
                    v-else
                    class="inline-block h-3 w-0.5 bg-hazard"
                    aria-hidden="true"
                />
                {{ eyebrow }}
            </p>
            <h2 :id="id" class="text-display text-balance">
                {{ title }}
            </h2>
            <p
                v-if="description"
                class="max-w-2xl text-[1.0625rem] leading-relaxed text-pretty text-muted-foreground"
                :class="align === 'center' ? 'mx-auto' : ''"
            >
                {{ description }}
            </p>
        </div>
    </Reveal>
</template>
