<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    CheckCheck,
    Clock,
    OctagonX,
    TriangleAlert,
    UserRoundCog,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import ChannelBadge from '@/components/domain/ChannelBadge.vue';
import DeadlineCountdown from '@/components/domain/DeadlineCountdown.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useFormat } from '@/composables/useFormat';
import { sp } from '@/lib/domain/routes';
import { enumLabel, roleLabel } from '@/lib/domain/status';
import { t, tc } from '@/lib/i18n';
import type {
    ApprovalDto,
    ApprovalStatus,
    RouteDto,
    UserRef,
} from '@/types/domain';

const props = withDefaults(
    defineProps<{
        route: RouteDto;
        requestId: number;
        candidates?: UserRef[];
        compact?: boolean;
    }>(),
    { candidates: () => [], compact: false },
);

const emit = defineEmits<{
    decide: [approval: ApprovalDto, mode: 'approve' | 'reject'];
}>();

const fmt = useFormat();
const reassigning = ref<number | null>(null);

const approvedCount = computed(
    () =>
        props.route.approvals.filter(
            (approval) => approval.status === 'approved',
        ).length,
);

const STATUS_TONE: Record<ApprovalStatus, string> = {
    pending: 'bg-info-soft text-info-soft-foreground border-info/30',
    approved: 'bg-success-soft text-success-soft-foreground border-success/30',
    rejected:
        'bg-destructive-soft text-destructive-soft-foreground border-destructive/30',
    expired: 'bg-muted text-muted-foreground border-border',
    revoked: 'bg-muted text-muted-foreground border-border',
    reassigned: 'bg-muted text-muted-foreground border-border',
};

function reassign(approval: ApprovalDto, user: UserRef): void {
    reassigning.value = approval.id;
    router.post(
        sp.requests.reassign(props.requestId, approval.id).url,
        { user_id: user.id },
        {
            preserveScroll: true,
            onFinish: () => {
                reassigning.value = null;
            },
        },
    );
}

function candidatesFor(approval: ApprovalDto): UserRef[] {
    const taken = new Set(
        props.route.approvals
            .filter(
                (item) =>
                    item.status === 'pending' || item.status === 'approved',
            )
            .map((item) => item.approver.id),
    );

    return props.candidates.filter(
        (user) => user.id !== approval.approver.id && !taken.has(user.id),
    );
}
</script>

<template>
    <div class="space-y-4" data-test="route-card" :data-status="route.status">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="space-y-1">
                <p class="text-sm font-medium" data-test="route-progress">
                    {{
                        t('scampause.route.progress', {
                            done: approvedCount,
                            total: route.approvals_required,
                        })
                    }}
                    <span
                        v-if="route.required_roles.length"
                        class="text-muted-foreground"
                    >
                        ·
                        {{
                            t('scampause.route.required_roles', {
                                roles: route.required_roles
                                    .map(roleLabel)
                                    .join(', '),
                            })
                        }}
                    </span>
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ enumLabel('route_status', route.status) }} ·
                    {{
                        t('scampause.route.attempt', { attempt: route.attempt })
                    }}
                    · {{ fmt.dateTime(route.started_at) }}
                </p>
            </div>
            <DeadlineCountdown
                v-if="route.status === 'active'"
                :at="route.expires_at"
                class="text-sm"
            />
        </div>

        <p
            v-if="route.solo_mode"
            class="hazard-edge-l flex items-start gap-2 rounded-md border border-warning/40 bg-warning-soft py-2 pr-3 pl-4 text-sm text-warning-soft-foreground"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{ t('scampause.route.solo') }}
        </p>
        <p
            v-if="route.incomplete"
            class="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive-soft px-3 py-2 text-sm text-destructive"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{ t('scampause.route.incomplete') }}
        </p>
        <p
            v-if="route.escalated_at"
            class="flex items-center gap-2 text-sm text-hazard-ink"
        >
            <Clock class="size-4" aria-hidden="true" />
            {{
                t('scampause.route.escalated', {
                    at: fmt.dateTime(route.escalated_at),
                })
            }}
        </p>

        <ul class="divide-y rounded-lg border">
            <li
                v-for="approval in route.approvals"
                :key="approval.id"
                class="flex flex-col gap-3 px-3 py-3 sm:flex-row sm:items-center sm:justify-between"
                data-test="route-slot"
                :data-status="approval.status"
            >
                <div class="flex min-w-0 items-start gap-3">
                    <span
                        class="mt-1 font-mono text-xs text-muted-foreground"
                        >{{ String(approval.slot).padStart(2, '0') }}</span
                    >
                    <UserAvatar
                        :name="approval.approver.name"
                        :src="approval.approver.avatar"
                        size="sm"
                    />
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ approval.approver.name }}
                        </p>
                        <p
                            class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            {{ roleLabel(approval.approver.role) }}
                            <ChannelBadge
                                v-if="approval.channel"
                                :channel="approval.channel"
                            />
                            <template v-if="approval.decided_at">
                                · {{ fmt.dateTime(approval.decided_at) }}
                            </template>
                            <template
                                v-else-if="approval.reminders_sent && !compact"
                            >
                                ·
                                {{
                                    tc(
                                        'scampause.route.reminders',
                                        approval.reminders_sent,
                                    )
                                }}
                            </template>
                        </p>
                        <p
                            v-if="approval.reject_reason"
                            class="mt-1 text-sm text-destructive"
                        >
                            {{
                                enumLabel(
                                    'decision_reason',
                                    approval.reject_reason,
                                )
                            }}
                        </p>
                        <p
                            v-if="approval.comment"
                            class="mt-1 text-sm text-pretty text-muted-foreground"
                        >
                            «{{ approval.comment }}»
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                    <span
                        :class="[
                            'inline-flex h-6 items-center rounded-sm border px-2 text-xs font-medium',
                            STATUS_TONE[approval.status],
                        ]"
                        >{{
                            enumLabel('approval_status', approval.status)
                        }}</span
                    >
                    <DropdownMenu
                        v-if="
                            approval.can_reassign &&
                            approval.status === 'pending' &&
                            candidatesFor(approval).length
                        "
                    >
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="reassigning === approval.id"
                                data-test="reassign"
                            >
                                <UserRoundCog aria-hidden="true" />
                                {{ t('scampause.route.reassign') }}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuLabel>{{
                                t('scampause.route.reassign_to')
                            }}</DropdownMenuLabel>
                            <DropdownMenuItem
                                v-for="user in candidatesFor(approval)"
                                :key="user.id"
                                @select="reassign(approval, user)"
                            >
                                {{ user.name }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <template v-if="approval.can_decide">
                        <Button
                            variant="destructive-outline"
                            size="sm"
                            data-test="route-reject"
                            @click="emit('decide', approval, 'reject')"
                        >
                            <OctagonX aria-hidden="true" />
                            {{ t('scampause.actions.reject') }}
                        </Button>
                        <Button
                            size="sm"
                            data-test="route-approve"
                            @click="emit('decide', approval, 'approve')"
                        >
                            <CheckCheck aria-hidden="true" />
                            {{ t('scampause.actions.approve') }}
                        </Button>
                    </template>
                </div>
            </li>
        </ul>

        <p v-if="route.rules.length" class="text-xs text-muted-foreground">
            {{ t('scampause.route.rules') }}:
            {{
                route.rules
                    .map((rule) => `${rule.name} v${rule.version}`)
                    .join(', ')
            }}
        </p>
    </div>
</template>
