<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoMark from '@/components/brand/AppLogoMark.vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        showName?: boolean;
        subtitle?: string | null;
        inverse?: boolean;
        size?: 'sm' | 'md';
    }>(),
    { showName: true, subtitle: null, inverse: false, size: 'md' },
);

const page = usePage();

const parts = computed(() => {
    const name = String(page.props.name ?? '').trim();
    const index = name.lastIndexOf(' ');

    return index > 0
        ? { brand: name.slice(0, index), suffix: name.slice(index + 1) }
        : { brand: name, suffix: '' };
});

const markSize = computed(() => (props.size === 'sm' ? 'size-7' : 'size-8'));
</script>

<template>
    <span class="flex min-w-0 items-center gap-2.5">
        <AppLogoMark :class="cn('shrink-0', markSize)" />
        <span v-if="showName" class="grid min-w-0 text-left leading-none">
            <span class="sr-only">{{ page.props.name }}</span>
            <span
                class="flex min-w-0 items-baseline gap-1.5"
                aria-hidden="true"
            >
                <span
                    class="truncate font-display text-[1.125rem] font-[650] tracking-[-0.01em] [font-stretch:85%]"
                    :class="inverse ? 'text-sidebar-accent-foreground' : ''"
                    >{{ parts.brand }}</span
                >
                <span
                    v-if="parts.suffix"
                    class="font-mono text-[0.625rem] font-medium tracking-[0.12em] uppercase"
                    :class="
                        inverse
                            ? 'text-sidebar-muted-foreground'
                            : 'text-muted-foreground'
                    "
                    >{{ parts.suffix }}</span
                >
            </span>
            <span
                v-if="subtitle"
                class="mt-1 truncate text-xs"
                :class="
                    inverse
                        ? 'text-sidebar-muted-foreground'
                        : 'text-muted-foreground'
                "
            >
                {{ subtitle }}
            </span>
        </span>
    </span>
</template>
