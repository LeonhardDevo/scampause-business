<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ShieldAlert, ShieldCheck, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import GuestShell from '@/components/domain/GuestShell.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { sp } from '@/lib/domain/routes';
import { enumLabel } from '@/lib/domain/status';
import { t } from '@/lib/i18n';
import type { EmployeeResponseSummary, Option } from '@/types/domain';

const props = defineProps<{
    summary: EmployeeResponseSummary;
    token: string;
    options: Option<'it_was_me' | 'not_me'>[];
}>();

const fmt = useFormat();
const form = useForm<{ response: 'it_was_me' | 'not_me' | '' }>({
    response: '',
});

const closed = computed(() => props.summary.answered || props.summary.expired);

function answer(response: 'it_was_me' | 'not_me'): void {
    if (closed.value || form.processing) {
        return;
    }

    form.response = response;
    form.post(sp.employee.store(props.token).url);
}
</script>

<template>
    <GuestShell>
        <Head :title="t('scampause.employee.title')" />
        <article
            class="mt-6 overflow-hidden rounded-xl border bg-card shadow-sm"
            data-test="employee-response"
        >
            <div class="hazard-edge-t px-5 pt-6 pb-5 sm:px-6">
                <p class="eyebrow text-[0.6875rem] text-hazard-ink">
                    {{ t('scampause.employee.eyebrow') }}
                </p>
                <h1
                    class="mt-1 font-display text-xl font-semibold text-balance"
                >
                    {{
                        t('scampause.employee.heading', {
                            name: summary.employee_first_name,
                        })
                    }}
                </h1>
                <p class="mt-2 text-sm text-pretty text-muted-foreground">
                    {{
                        t('scampause.employee.body', {
                            company: summary.company,
                        })
                    }}
                </p>
            </div>
            <dl
                class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 border-y bg-muted/30 px-5 py-4 text-sm sm:px-6"
            >
                <dt class="text-muted-foreground">
                    {{ t('scampause.employee.new_account') }}
                </dt>
                <dd class="font-mono font-medium">
                    {{ summary.payee_masked }}
                </dd>
                <template v-if="summary.bank">
                    <dt class="text-muted-foreground">
                        {{ t('scampause.employee.bank') }}
                    </dt>
                    <dd>{{ summary.bank }}</dd>
                </template>
                <dt class="text-muted-foreground">
                    {{ t('scampause.fields.reference') }}
                </dt>
                <dd class="font-mono">{{ summary.reference }}</dd>
                <dt class="text-muted-foreground">
                    {{ t('scampause.employee.valid_until') }}
                </dt>
                <dd>{{ fmt.dateTime(summary.expires_at) }}</dd>
            </dl>
            <div class="space-y-4 px-5 py-5 sm:px-6">
                <p
                    v-if="summary.answered"
                    class="flex items-start gap-2 rounded-md border bg-muted px-3 py-2 text-sm"
                    data-test="already-answered"
                >
                    <ShieldCheck
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    {{
                        t('scampause.employee.already', {
                            response: summary.response
                                ? enumLabel(
                                      'employee_response',
                                      summary.response,
                                  )
                                : '—',
                        })
                    }}
                </p>
                <p
                    v-else-if="summary.expired"
                    class="flex items-start gap-2 rounded-md border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground"
                    data-test="expired"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    {{ t('scampause.employee.expired') }}
                </p>
                <template v-else>
                    <p class="text-sm font-medium">
                        {{ t('scampause.employee.question') }}
                    </p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <Button
                            size="lg"
                            variant="outline"
                            :disabled="form.processing"
                            data-test="answer-me"
                            @click="answer('it_was_me')"
                        >
                            <Spinner
                                v-if="
                                    form.processing &&
                                    form.response === 'it_was_me'
                                "
                            />
                            <ShieldCheck v-else aria-hidden="true" />
                            {{ t('scampause.employee.it_was_me') }}
                        </Button>
                        <Button
                            size="lg"
                            variant="destructive"
                            :disabled="form.processing"
                            data-test="answer-not-me"
                            @click="answer('not_me')"
                        >
                            <Spinner
                                v-if="
                                    form.processing &&
                                    form.response === 'not_me'
                                "
                            />
                            <ShieldAlert v-else aria-hidden="true" />
                            {{ t('scampause.employee.not_me') }}
                        </Button>
                    </div>
                    <p
                        v-if="form.errors.response"
                        class="text-sm text-destructive"
                        role="alert"
                    >
                        {{ form.errors.response }}
                    </p>
                    <p class="text-xs text-pretty text-muted-foreground">
                        {{ t('scampause.employee.footnote') }}
                    </p>
                </template>
            </div>
        </article>
    </GuestShell>
</template>
