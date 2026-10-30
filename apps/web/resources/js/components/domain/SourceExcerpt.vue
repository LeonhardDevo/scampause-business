<script setup lang="ts">
import { computed } from 'vue';
import { buildExcerpt, countMatches } from '@/lib/domain/excerpt';
import { t, tc } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{ html: string; phrases?: string[] }>(),
    { phrases: () => [] },
);

const segments = computed(() => buildExcerpt(props.html, props.phrases));
const phraseCount = computed(() => countMatches(segments.value));
</script>

<template>
    <figure class="space-y-2" data-test="source-excerpt">
        <figcaption
            class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
        >
            <span>{{ t('scampause.excerpt.caption') }}</span>
            <span class="flex flex-wrap items-center gap-3">
                <span class="inline-flex items-center gap-1.5"
                    ><span
                        class="inline-block size-3 rounded-sm bg-risk-high-tint ring-1 ring-risk-high/40"
                        aria-hidden="true"
                    />{{ t('scampause.excerpt.legend_phrase') }}</span
                >
                <span class="inline-flex items-center gap-1.5"
                    ><span
                        class="inline-block size-3 rounded-sm bg-accent ring-1 ring-ring/40"
                        aria-hidden="true"
                    />{{ t('scampause.excerpt.legend_value') }}</span
                >
                <span
                    v-if="phrases.length"
                    class="font-medium text-hazard-ink"
                    >{{ tc('scampause.excerpt.matches', phraseCount) }}</span
                >
            </span>
        </figcaption>
        <pre
            class="max-h-[28rem] overflow-auto rounded-lg border bg-muted/40 p-3 font-mono text-[0.8125rem] leading-6 break-words whitespace-pre-wrap"
            tabindex="0"
            :aria-label="t('scampause.excerpt.caption')"
        ><template v-for="(segment, index) in segments" :key="index"><mark
                    v-if="segment.kind === 'phrase'"
                    class="rounded-sm bg-risk-high-tint px-0.5 font-semibold text-risk-high"
                    data-test="pressure-phrase"
                >{{ segment.text }}</mark><mark
                    v-else-if="segment.kind === 'value'"
                    class="rounded-sm bg-accent px-0.5 text-accent-foreground underline decoration-ring decoration-2"
                >{{ segment.text }}</mark><template v-else>{{ segment.text }}</template></template></pre>
    </figure>
</template>
