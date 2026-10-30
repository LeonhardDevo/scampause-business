<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        masked?: string | null;
        last4?: string | null;
        class?: string;
    }>(),
    { masked: null, last4: null, class: '' },
);

const digits = computed(() => {
    if (props.last4) {
        return props.last4;
    }

    const match = (props.masked ?? '').match(/(\w{2,4})\s*$/);

    return match ? match[1] : null;
});

const display = computed(() => {
    if (props.masked) {
        return props.masked;
    }

    return digits.value ? `•••• ${digits.value}` : '—';
});
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex max-w-full items-center rounded-sm bg-muted px-1.5 py-0.5 font-mono text-[0.8125rem] font-medium tracking-[0.02em] whitespace-nowrap tabular-nums',
                $props.class,
            )
        "
        data-test="masked-account"
    >
        <span class="truncate" aria-hidden="true">{{ display }}</span>
        <span class="sr-only">{{
            digits
                ? t('scampause.common.account_ending', { last4: digits })
                : t('scampause.common.no_account')
        }}</span>
    </span>
</template>
