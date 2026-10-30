<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CircleAlert, ShieldAlert, ShieldCheck } from '@lucide/vue';
import GuestShell from '@/components/domain/GuestShell.vue';
import { t } from '@/lib/i18n';

defineProps<{
    response: 'it_was_me' | 'not_me';
    message: string;
    error: boolean;
}>();
</script>

<template>
    <GuestShell>
        <Head :title="t('scampause.employee.title')" />
        <section
            class="mt-6 rounded-xl border bg-card p-6 text-center shadow-sm"
            role="status"
            data-test="employee-done"
            :data-response="response"
        >
            <span
                :class="[
                    'mx-auto inline-flex size-12 items-center justify-center rounded-full border',
                    error
                        ? 'bg-warning-soft text-hazard-ink'
                        : response === 'not_me'
                          ? 'bg-risk-critical-tint text-risk-critical'
                          : 'bg-success-soft text-success',
                ]"
                aria-hidden="true"
            >
                <CircleAlert v-if="error" class="size-6" />
                <ShieldAlert v-else-if="response === 'not_me'" class="size-6" />
                <ShieldCheck v-else class="size-6" />
            </span>
            <h1 class="mt-4 font-display text-xl font-semibold">
                {{
                    error
                        ? t('scampause.employee.done_error')
                        : t('scampause.employee.done_title')
                }}
            </h1>
            <p class="mt-2 text-sm text-pretty text-muted-foreground">
                {{ message }}
            </p>
            <p class="mt-4 text-xs text-muted-foreground">
                {{ t('scampause.employee.close_hint') }}
            </p>
        </section>
    </GuestShell>
</template>
