<script setup lang="ts">
import { ArrowRightLeft } from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BankDiffRow } from '@/types/brand';

const props = withDefaults(
    defineProps<{
        rows: BankDiffRow[];
        caption?: string | null;
        dense?: boolean;
        class?: string;
    }>(),
    { caption: null, dense: false, class: '' },
);

const pad = computed(() => (props.dense ? 'px-2' : 'px-3'));
</script>

<template>
    <div :class="cn('overflow-hidden rounded-lg border bg-card', $props.class)">
        <table class="w-full table-fixed text-left text-sm">
            <caption v-if="caption" class="sr-only">
                {{
                    caption
                }}
            </caption>
            <thead class="border-b bg-muted/50">
                <tr>
                    <th
                        scope="col"
                        :class="['py-2', pad, dense ? 'w-[22%]' : 'w-[30%]']"
                    >
                        <span class="sr-only">{{
                            t('ui.bank_diff.field')
                        }}</span>
                    </th>
                    <th
                        scope="col"
                        :class="[
                            'py-2 eyebrow text-[0.6875rem] text-muted-foreground',
                            pad,
                        ]"
                    >
                        {{ t('ui.bank_diff.on_record') }}
                    </th>
                    <th
                        scope="col"
                        :class="[
                            'py-2 eyebrow text-[0.6875rem] text-muted-foreground',
                            pad,
                        ]"
                    >
                        {{ t('ui.bank_diff.in_request') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr
                    v-for="row in rows"
                    :key="row.key"
                    :class="row.changed ? 'bg-risk-critical-tint' : ''"
                >
                    <th
                        scope="row"
                        :class="[
                            'truncate font-medium',
                            pad,
                            dense ? 'py-1.5 text-xs' : 'py-2.5',
                        ]"
                    >
                        {{ row.label }}
                    </th>
                    <td
                        :class="[
                            'truncate text-muted-foreground',
                            pad,
                            dense ? 'py-1.5 text-xs' : 'py-2.5',
                            row.mono ? 'font-mono tabular-nums' : '',
                        ]"
                    >
                        <span
                            :aria-hidden="row.srOnRecord ? 'true' : undefined"
                            >{{ row.onRecord }}</span
                        >
                        <span v-if="row.srOnRecord" class="sr-only">{{
                            row.srOnRecord
                        }}</span>
                    </td>
                    <td
                        :class="[
                            pad,
                            dense ? 'py-1.5 text-xs' : 'py-2.5',
                            row.mono ? 'font-mono tabular-nums' : '',
                            row.changed
                                ? 'font-semibold text-risk-critical'
                                : '',
                        ]"
                    >
                        <span class="flex min-w-0 items-center gap-1.5">
                            <ArrowRightLeft
                                v-if="row.changed"
                                class="size-3.5 shrink-0"
                                :stroke-width="2"
                                aria-hidden="true"
                            />
                            <span
                                class="truncate"
                                :aria-hidden="
                                    row.srInRequest ? 'true' : undefined
                                "
                                >{{ row.inRequest }}</span
                            >
                            <span v-if="row.srInRequest" class="sr-only">{{
                                row.srInRequest
                            }}</span>
                            <span v-if="row.changed" class="sr-only">{{
                                t('ui.bank_diff.changed')
                            }}</span>
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
