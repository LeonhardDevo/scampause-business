import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import type { DomainShared } from '@/types/domain';

export function useScamPause(): ComputedRef<DomainShared | null> {
    const page = usePage();

    return computed(() => page.props.scampause ?? null);
}
