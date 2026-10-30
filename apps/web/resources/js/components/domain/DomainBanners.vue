<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Gauge, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useScamPause } from '@/composables/domain/useScamPause';
import { useCan } from '@/composables/useCan';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';

const shared = useScamPause();
const page = usePage();
const { can } = useCan();

const limitReached = computed(() => {
    const usage = shared.value?.plan_usage.payment_requests;

    return Boolean(usage && usage.limit !== null && usage.used >= usage.limit);
});

const isSettings = computed(() => page.url.startsWith('/settings'));
</script>

<template>
    <div
        v-if="shared && !isSettings && (shared.solo_mode || limitReached)"
        class="mx-auto flex w-full max-w-[90rem] flex-col gap-2 px-4 pt-4 sm:px-6"
        data-print-hide
    >
        <div
            v-if="shared.solo_mode"
            role="status"
            class="hazard-edge-l flex flex-col gap-3 rounded-lg border border-warning/40 bg-warning-soft py-3 pr-4 pl-5 text-sm text-warning-soft-foreground sm:flex-row sm:items-center sm:justify-between"
            data-test="solo-mode-banner"
        >
            <p class="flex items-start gap-2">
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    <strong class="font-semibold">{{
                        t('scampause.banners.solo_title')
                    }}</strong>
                    {{ t('scampause.banners.solo_body') }}
                </span>
            </p>
            <Button
                v-if="can('members.manage')"
                as-child
                variant="hazard"
                size="sm"
                class="shrink-0"
            >
                <Link :href="endpoints.settings.members.index()">{{
                    t('scampause.banners.solo_action')
                }}</Link>
            </Button>
        </div>
        <div
            v-if="limitReached"
            role="status"
            class="flex flex-col gap-3 rounded-lg border border-info/30 bg-info-soft px-4 py-3 text-sm text-info-soft-foreground sm:flex-row sm:items-center sm:justify-between"
            data-test="plan-limit-banner"
        >
            <p class="flex items-start gap-2">
                <Gauge class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                {{ t('scampause.banners.limit_body') }}
            </p>
            <Button
                v-if="can('billing.manage')"
                as-child
                variant="outline"
                size="sm"
                class="shrink-0"
            >
                <Link :href="endpoints.settings.billing.show()">{{
                    t('scampause.banners.limit_action')
                }}</Link>
            </Button>
        </div>
    </div>
</template>
