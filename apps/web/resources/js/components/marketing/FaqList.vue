<script setup lang="ts">
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';

defineProps<{
    items: Array<{ id: string; question: string; answer: string }>;
}>();
</script>

<template>
    <Accordion
        type="single"
        collapsible
        class="divide-y rounded-xl border bg-card px-2 shadow-soft sm:px-4"
    >
        <AccordionItem
            v-for="(item, index) in items"
            :key="item.id"
            :value="item.id"
            class="border-b-0"
        >
            <AccordionTrigger
                class="gap-4 py-5 text-left text-base font-semibold hover:no-underline"
            >
                <span class="flex gap-4">
                    <span
                        class="mt-0.5 font-mono text-xs font-medium text-muted-foreground tabular-nums"
                        aria-hidden="true"
                        >{{ String(index + 1).padStart(2, '0') }}</span
                    >
                    <span>{{ item.question }}</span>
                </span>
            </AccordionTrigger>
            <AccordionContent
                class="pb-5 pl-9 text-[0.9375rem] leading-relaxed text-pretty text-muted-foreground"
            >
                {{ item.answer }}
            </AccordionContent>
        </AccordionItem>
    </Accordion>
</template>
