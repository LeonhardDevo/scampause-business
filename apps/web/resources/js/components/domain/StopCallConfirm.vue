<script setup lang="ts">
import { Ban, PhoneCall, ShieldCheck, Stamp } from '@lucide/vue';
import CopyButton from '@/components/common/CopyButton.vue';
import { enumLabel } from '@/lib/domain/status';
import { t } from '@/lib/i18n';
import type { CallbackContact } from '@/types/domain';

withDefaults(
    defineProps<{
        contacts?: CallbackContact[];
        showContacts?: boolean;
        missingContact?: boolean;
    }>(),
    { contacts: () => [], showContacts: false, missingContact: false },
);

const steps = [
    { key: 'stop', icon: Ban },
    { key: 'call', icon: PhoneCall },
    { key: 'confirm', icon: Stamp },
] as const;
</script>

<template>
    <aside
        class="overflow-hidden rounded-xl border bg-card shadow-xs"
        :aria-label="t('scampause.scc.title')"
        data-test="stop-call-confirm"
    >
        <div class="hazard-edge-t px-4 pt-5 pb-4">
            <p class="eyebrow text-[0.6875rem] text-hazard-ink">
                {{ t('scampause.scc.eyebrow') }}
            </p>
            <h2 class="mt-1 font-display text-lg font-semibold">
                {{ t('scampause.scc.title') }}
            </h2>
        </div>
        <ol class="space-y-3 px-4 pb-4">
            <li
                v-for="(step, index) in steps"
                :key="step.key"
                class="flex items-start gap-3"
            >
                <span
                    class="inline-flex size-7 shrink-0 items-center justify-center rounded-md border bg-muted/60 font-mono text-xs font-semibold"
                    aria-hidden="true"
                    >{{ index + 1 }}</span
                >
                <div class="min-w-0">
                    <p class="flex items-center gap-1.5 text-sm font-semibold">
                        <component
                            :is="step.icon"
                            class="size-4 text-muted-foreground"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        {{ t(`scampause.scc.${step.key}_title`) }}
                    </p>
                    <p class="text-sm text-pretty text-muted-foreground">
                        {{ t(`scampause.scc.${step.key}_body`) }}
                    </p>
                </div>
            </li>
        </ol>
        <div
            class="mx-4 mb-4 flex items-start gap-2 rounded-md border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground"
        >
            <Ban class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{ t('scampause.scc.never_message_numbers') }}
        </div>
        <div v-if="showContacts" class="border-t px-4 py-4">
            <p class="eyebrow text-[0.6875rem] text-muted-foreground">
                {{ t('scampause.scc.official_contact') }}
            </p>
            <ul v-if="contacts.length" class="mt-2 space-y-2">
                <li
                    v-for="contact in contacts"
                    :key="contact.id"
                    class="flex items-start justify-between gap-2"
                    data-test="callback-contact"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ contact.name }}</p>
                        <p class="font-mono text-sm tabular-nums">
                            {{ contact.phone_display }}
                        </p>
                        <p class="text-xs text-pretty text-muted-foreground">
                            <ShieldCheck
                                class="mr-1 inline size-3.5 align-[-2px]"
                                aria-hidden="true"
                            />{{ enumLabel('contact_source', contact.source)
                            }}<template v-if="contact.source_ref">
                                · {{ contact.source_ref }}</template
                            >
                        </p>
                    </div>
                    <CopyButton
                        :value="contact.phone_display"
                        :label="t('scampause.scc.copy_number')"
                    />
                </li>
            </ul>
            <p
                v-else
                class="mt-2 text-sm text-pretty text-hazard-ink"
                data-test="no-callback-contact"
            >
                {{
                    missingContact
                        ? t('scampause.scc.no_contact_vendor')
                        : t('scampause.scc.no_contact')
                }}
            </p>
        </div>
    </aside>
</template>
