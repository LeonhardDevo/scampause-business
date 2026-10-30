<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import DeadlineCountdown from '@/components/domain/DeadlineCountdown.vue';
import RiskBadge from '@/components/domain/RiskBadge.vue';
import StatusChip from '@/components/domain/StatusChip.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useRowKeys } from '@/composables/domain/useRowKeys';
import { useFormat } from '@/composables/useFormat';
import { requestPath } from '@/lib/domain/routes';
import { enumLabel, isOpen } from '@/lib/domain/status';
import { t } from '@/lib/i18n';
import type { PaymentRequestRow } from '@/types/domain';

withDefaults(
    defineProps<{
        rows: PaymentRequestRow[];
        caption: string;
        dense?: boolean;
        showRequester?: boolean;
        loading?: boolean;
        testId?: string;
    }>(),
    {
        dense: false,
        showRequester: true,
        loading: false,
        testId: 'requests-table',
    },
);

const container = ref<HTMLElement | null>(null);
const fmt = useFormat();

useRowKeys(container);

function open(row: PaymentRequestRow): void {
    router.visit(requestPath(row.id));
}

function deadline(row: PaymentRequestRow): string | null {
    if (row.status === 'awaiting_approval' && row.expires_at) {
        return row.expires_at;
    }

    return null;
}
</script>

<template>
    <div ref="container" :data-test="testId">
        <div class="hidden overflow-x-auto rounded-xl border bg-card md:block">
            <table class="w-full text-sm">
                <caption class="sr-only">
                    {{
                        caption
                    }}
                    ·
                    {{
                        t('scampause.table.keyboard_hint')
                    }}
                </caption>
                <thead class="sticky top-0 z-10 border-b bg-card">
                    <tr class="text-left text-xs text-muted-foreground">
                        <th scope="col" class="px-3 py-2.5 font-medium">
                            {{ t('scampause.fields.reference') }}
                        </th>
                        <th scope="col" class="px-3 py-2.5 font-medium">
                            {{ t('scampause.fields.title') }}
                        </th>
                        <th
                            scope="col"
                            class="hidden px-3 py-2.5 font-medium xl:table-cell"
                        >
                            {{ t('scampause.fields.vendor') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            {{ t('scampause.fields.amount') }}
                        </th>
                        <th scope="col" class="px-3 py-2.5 font-medium">
                            {{ t('scampause.fields.risk') }}
                        </th>
                        <th scope="col" class="px-3 py-2.5 font-medium">
                            {{ t('scampause.fields.status') }}
                        </th>
                        <th
                            scope="col"
                            class="hidden px-3 py-2.5 font-medium 2xl:table-cell"
                        >
                            {{ t('scampause.fields.due') }}
                        </th>
                        <th
                            v-if="showRequester"
                            scope="col"
                            class="hidden px-3 py-2.5 font-medium xl:table-cell"
                        >
                            {{ t('scampause.fields.requested_by') }}
                        </th>
                    </tr>
                </thead>
                <tbody v-if="loading" class="divide-y">
                    <tr v-for="index in 8" :key="index">
                        <td :colspan="showRequester ? 8 : 7" class="px-3 py-3">
                            <Skeleton class="h-5 w-full" />
                        </td>
                    </tr>
                </tbody>
                <tbody v-else class="divide-y">
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        tabindex="0"
                        data-row-nav
                        data-test="request-row"
                        :data-reference="row.reference"
                        :class="[
                            'cursor-pointer transition-colors outline-none hover:bg-muted/60 focus-visible:bg-accent focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring',
                            dense ? 'h-9' : 'h-11',
                        ]"
                        @click="open(row)"
                        @keydown.enter="open(row)"
                    >
                        <td
                            class="px-3 font-mono text-[0.8125rem] font-medium whitespace-nowrap"
                        >
                            {{ row.reference }}
                        </td>
                        <td class="max-w-[16rem] px-3">
                            <p class="truncate font-medium">{{ row.title }}</p>
                            <p
                                v-if="!dense"
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{
                                    enumLabel('payment_request_kind', row.kind)
                                }}
                            </p>
                        </td>
                        <td
                            class="hidden max-w-[14rem] truncate px-3 xl:table-cell"
                        >
                            {{
                                row.vendor?.name ??
                                t('scampause.common.unknown_vendor')
                            }}
                        </td>
                        <td
                            class="px-3 text-right font-medium whitespace-nowrap tabular-nums"
                        >
                            {{ row.amount?.formatted ?? '—' }}
                        </td>
                        <td class="px-3">
                            <RiskBadge
                                v-if="row.risk"
                                :level="row.risk.level"
                                :score="row.risk.score"
                            />
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3">
                            <StatusChip :status="row.status" />
                        </td>
                        <td
                            class="hidden px-3 whitespace-nowrap 2xl:table-cell"
                        >
                            <DeadlineCountdown
                                v-if="deadline(row)"
                                :at="deadline(row)"
                            />
                            <span
                                v-else-if="row.due_date && isOpen(row.status)"
                                class="text-xs text-muted-foreground"
                                >{{ fmt.date(row.due_date) }}</span
                            >
                            <RelativeTime
                                v-else
                                :value="row.created_at"
                                class="text-xs text-muted-foreground"
                            />
                        </td>
                        <td
                            v-if="showRequester"
                            class="hidden px-3 xl:table-cell"
                        >
                            <span class="flex items-center gap-2">
                                <UserAvatar
                                    :name="row.requested_by.name"
                                    :src="row.requested_by.avatar"
                                    size="xs"
                                />
                                <span class="truncate text-xs">{{
                                    row.requested_by.name
                                }}</span>
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ul class="space-y-2 md:hidden">
            <template v-if="loading">
                <li v-for="index in 4" :key="index">
                    <Skeleton class="h-24 w-full rounded-xl" />
                </li>
            </template>
            <li v-for="row in rows" v-else :key="row.id">
                <button
                    type="button"
                    class="w-full rounded-xl border bg-card p-3 text-left transition-colors hover:border-ring/40 hover:bg-accent/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    data-row-nav
                    data-test="request-card"
                    @click="open(row)"
                >
                    <span class="flex items-center justify-between gap-2">
                        <span class="font-mono text-[0.8125rem] font-medium">{{
                            row.reference
                        }}</span>
                        <StatusChip :status="row.status" />
                    </span>
                    <span class="mt-1 block truncate font-medium">{{
                        row.title
                    }}</span>
                    <span
                        class="block truncate text-sm text-muted-foreground"
                        >{{
                            row.vendor?.name ??
                            t('scampause.common.unknown_vendor')
                        }}</span
                    >
                    <span class="mt-2 flex items-center justify-between gap-2">
                        <span class="font-semibold tabular-nums">{{
                            row.amount?.formatted ?? '—'
                        }}</span>
                        <RiskBadge
                            v-if="row.risk"
                            :level="row.risk.level"
                            :score="row.risk.score"
                            :tooltip="false"
                        />
                    </span>
                </button>
            </li>
        </ul>
    </div>
</template>
