<script setup lang="ts">
import { AtSign, Globe, Info, PhoneOff, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import DoNotUseChip from '@/components/domain/DoNotUseChip.vue';
import { useFormat } from '@/composables/useFormat';
import { describeLookalike } from '@/lib/domain/lookalike';
import { enumLabel } from '@/lib/domain/status';
import { t, tc } from '@/lib/i18n';
import type { SenderDomain, SenderPanelDto } from '@/types/domain';

const props = defineProps<{ sender: SenderPanelDto }>();

const fmt = useFormat();

function domainOf(email: string | null): string | null {
    if (!email || !email.includes('@')) {
        return null;
    }

    return email.split('@').pop()?.toLowerCase() ?? null;
}

const replyMismatch = computed(() => {
    const from = domainOf(props.sender.from_email);
    const reply = domainOf(props.sender.reply_to);

    return Boolean(from && reply && from !== reply);
});

const authItems = computed(() =>
    (['spf', 'dkim', 'dmarc'] as const).map((key) => ({
        key,
        value: props.sender.auth[key],
    })),
);

function authTone(value: string | null): string {
    if (value === 'pass') {
        return 'bg-success-soft text-success-soft-foreground border-success/30';
    }

    if (value === 'fail' || value === 'softfail' || value === 'permerror') {
        return 'bg-destructive-soft text-destructive border-destructive/30';
    }

    return 'bg-muted text-muted-foreground border-border';
}

function lookalike(domain: SenderDomain): {
    segments: Array<{ text: string; changed: boolean }>;
    text: string;
} | null {
    if (!domain.lookalike_of) {
        return null;
    }

    const description = describeLookalike(
        domain.domain,
        domain.lookalike_of,
        domain.technique,
    );

    return {
        segments: description.segments,
        text: tc(
            `scampause.sender.lookalike.${description.kind}`,
            Math.max(1, description.count),
            { original: domain.lookalike_of },
        ),
    };
}

const phones = computed(() =>
    props.sender.phones_in_message.filter((phone) => !phone.matches_master),
);
const knownPhones = computed(() =>
    props.sender.phones_in_message.filter((phone) => phone.matches_master),
);
</script>

<template>
    <div class="space-y-5" data-test="sender-panel">
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-xs text-muted-foreground">
                    {{ t('scampause.sender.from') }}
                </dt>
                <dd class="flex min-w-0 items-center gap-1.5">
                    <AtSign
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="truncate">
                        <template v-if="sender.from_name"
                            >{{ sender.from_name }} ·
                        </template>
                        <span class="font-mono">{{
                            sender.from_email ?? '—'
                        }}</span>
                    </span>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">
                    {{ t('scampause.sender.reply_to') }}
                </dt>
                <dd
                    :class="[
                        'font-mono break-all',
                        replyMismatch ? 'font-semibold text-risk-critical' : '',
                    ]"
                    data-test="reply-to"
                >
                    {{ sender.reply_to ?? '—' }}
                    <span
                        v-if="replyMismatch"
                        class="ml-1 inline-flex h-5 items-center rounded-sm border border-destructive/30 bg-risk-critical-tint px-1.5 font-sans text-[0.6875rem] font-medium"
                        >{{ t('scampause.sender.reply_mismatch') }}</span
                    >
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">
                    {{ t('scampause.sender.return_path') }}
                </dt>
                <dd class="font-mono break-all">
                    {{ sender.return_path_domain ?? '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">
                    {{ t('scampause.sender.auth') }}
                </dt>
                <dd class="mt-0.5 flex flex-wrap gap-1.5">
                    <span
                        v-for="item in authItems"
                        :key="item.key"
                        :class="[
                            'inline-flex h-6 items-center gap-1 rounded-sm border px-2 text-xs font-medium',
                            authTone(item.value),
                        ]"
                        :data-test="`auth-${item.key}`"
                    >
                        <span class="font-mono uppercase">{{ item.key }}</span>
                        {{
                            item.value
                                ? enumLabel('auth_result', item.value)
                                : t('scampause.sender.not_checked')
                        }}
                    </span>
                </dd>
            </div>
        </dl>

        <div
            v-if="sender.domains.length"
            class="overflow-x-auto rounded-lg border"
        >
            <table class="w-full min-w-[36rem] text-sm">
                <caption class="sr-only">
                    {{
                        t('scampause.sender.domains_caption')
                    }}
                </caption>
                <thead class="border-b bg-muted/50 text-left">
                    <tr>
                        <th
                            scope="col"
                            class="px-3 py-2 text-xs font-medium text-muted-foreground"
                        >
                            {{ t('scampause.sender.domain') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-xs font-medium text-muted-foreground"
                        >
                            {{ t('scampause.sender.role') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-xs font-medium text-muted-foreground"
                        >
                            {{ t('scampause.sender.findings') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-xs font-medium text-muted-foreground"
                        >
                            {{ t('scampause.sender.age') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="domain in sender.domains"
                        :key="`${domain.role}-${domain.domain}`"
                        :class="
                            domain.lookalike_of
                                ? 'bg-risk-critical-tint/60'
                                : ''
                        "
                        data-test="sender-domain"
                    >
                        <td class="px-3 py-2.5 align-top">
                            <p class="flex items-center gap-1.5 font-mono">
                                <Globe
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <template v-if="lookalike(domain)">
                                    <span
                                        v-for="(segment, index) in lookalike(
                                            domain,
                                        )?.segments"
                                        :key="index"
                                        :class="
                                            segment.changed
                                                ? 'font-semibold text-risk-critical underline decoration-2'
                                                : ''
                                        "
                                        >{{ segment.text }}</span
                                    >
                                </template>
                                <span v-else>{{ domain.domain }}</span>
                            </p>
                            <p
                                v-if="
                                    domain.unicode &&
                                    domain.unicode !== domain.domain
                                "
                                class="text-xs text-muted-foreground"
                            >
                                {{ domain.unicode }}
                            </p>
                        </td>
                        <td class="px-3 py-2.5 align-top text-muted-foreground">
                            {{ t(`scampause.sender.roles.${domain.role}`) }}
                        </td>
                        <td class="space-y-1 px-3 py-2.5 align-top">
                            <p
                                v-if="lookalike(domain)"
                                class="text-sm font-medium text-risk-critical"
                            >
                                {{ lookalike(domain)?.text }}
                            </p>
                            <div class="flex flex-wrap gap-1">
                                <span
                                    v-if="domain.known_for_vendor"
                                    class="inline-flex h-5 items-center gap-1 rounded-sm border border-success/30 bg-success-soft px-1.5 text-[0.6875rem] font-medium text-success-soft-foreground"
                                    ><ShieldCheck
                                        class="size-3"
                                        aria-hidden="true"
                                    />{{ t('scampause.sender.known') }}</span
                                >
                                <span
                                    v-if="domain.free_mail"
                                    class="inline-flex h-5 items-center rounded-sm border border-warning/30 bg-warning-soft px-1.5 text-[0.6875rem] font-medium text-warning-soft-foreground"
                                    >{{ t('scampause.sender.free_mail') }}</span
                                >
                                <span
                                    v-if="domain.dmarc_policy"
                                    class="inline-flex h-5 items-center rounded-sm border bg-muted px-1.5 font-mono text-[0.6875rem] text-muted-foreground"
                                    >DMARC p={{ domain.dmarc_policy }}</span
                                >
                            </div>
                        </td>
                        <td class="px-3 py-2.5 align-top text-sm">
                            <span
                                v-if="domain.age_days !== null"
                                :class="
                                    domain.age_days < 90
                                        ? 'font-semibold text-risk-high'
                                        : ''
                                "
                                >{{
                                    tc(
                                        'scampause.sender.age_days',
                                        domain.age_days,
                                    )
                                }}</span
                            >
                            <span v-else class="text-muted-foreground">{{
                                t('scampause.sender.age_unknown')
                            }}</span>
                            <p
                                v-if="domain.checked_at"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    t('scampause.sender.first_checked', {
                                        date: fmt.date(domain.checked_at),
                                    })
                                }}
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="sender.phones_in_message.length" class="space-y-2">
            <h3
                class="flex items-center gap-1.5 eyebrow text-[0.6875rem] text-muted-foreground"
            >
                <PhoneOff class="size-3.5" aria-hidden="true" />
                {{ t('scampause.sender.phones_title') }}
            </h3>
            <p class="text-sm text-pretty text-muted-foreground">
                {{ t('scampause.sender.phones_body') }}
            </p>
            <div class="flex flex-wrap gap-2">
                <DoNotUseChip
                    v-for="phone in phones"
                    :key="phone.last4"
                    :value="`•••• ${phone.last4}`"
                />
                <span
                    v-for="phone in knownPhones"
                    :key="`known-${phone.last4}`"
                    class="inline-flex h-7 items-center gap-1.5 rounded-sm border border-success/30 bg-success-soft px-2 font-mono text-xs text-success-soft-foreground"
                    ><ShieldCheck class="size-3.5" aria-hidden="true" />••••
                    {{ phone.last4 }}
                    <span class="font-sans">{{
                        t('scampause.sender.matches_master')
                    }}</span></span
                >
            </div>
        </div>

        <ul v-if="sender.notes?.length" class="space-y-1.5">
            <li
                v-for="note in sender.notes"
                :key="note"
                class="flex items-start gap-2 text-sm text-muted-foreground"
            >
                <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                {{ note }}
            </li>
        </ul>
    </div>
</template>
