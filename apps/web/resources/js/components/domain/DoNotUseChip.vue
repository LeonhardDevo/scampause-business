<script setup lang="ts">
import { Ban } from '@lucide/vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { t } from '@/lib/i18n';

defineProps<{ value: string }>();
</script>

<template>
    <TooltipProvider :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    class="inline-flex h-7 items-center gap-1.5 rounded-sm border border-warning/40 bg-warning-soft px-2 font-mono text-xs text-hazard-ink"
                    tabindex="0"
                    data-test="do-not-use-chip"
                >
                    <Ban
                        class="size-3.5"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                    <span class="line-through decoration-1">{{ value }}</span>
                    <span class="sr-only">{{
                        t('scampause.sender.do_not_use')
                    }}</span>
                </span>
            </TooltipTrigger>
            <TooltipContent class="max-w-64">{{
                t('scampause.sender.do_not_use')
            }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
