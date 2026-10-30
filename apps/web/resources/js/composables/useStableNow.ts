import { usePage } from '@inertiajs/vue3';
import { useMounted, useNow } from '@vueuse/core';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { toDate } from '@/lib/format';

export function useStableNow(interval = 30_000): ComputedRef<Date> {
    const page = usePage();
    const mounted = useMounted();
    const live = useNow({ interval });

    return computed(() =>
        mounted.value ? live.value : (toDate(page.props.now) ?? live.value),
    );
}
