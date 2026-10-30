<script setup lang="ts">
import { ChartNoAxesColumn, Table2 } from '@lucide/vue';
import type { EChartsCoreOption } from 'echarts/core';
import { ref } from 'vue';
import ThemedChart from '@/components/charts/ThemedChart.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ErrorState from '@/components/common/ErrorState.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { t } from '@/lib/i18n';
import type { ChartTheme } from '@/lib/theme';

withDefaults(
    defineProps<{
        title: string;
        description?: string | null;
        summary: string;
        build: (theme: ChartTheme) => EChartsCoreOption;
        columns: string[];
        rows: Array<Array<string | number>>;
        empty?: boolean;
        loading?: boolean;
        error?: boolean;
        retrying?: boolean;
        testId?: string | null;
    }>(),
    {
        description: null,
        empty: false,
        loading: false,
        error: false,
        retrying: false,
        testId: null,
    },
);

defineEmits<{ retry: [] }>();

const asTable = ref(false);
</script>

<template>
    <section
        class="flex min-w-0 flex-col rounded-xl border bg-card shadow-xs"
        :data-test="testId ?? undefined"
    >
        <header
            class="flex items-start justify-between gap-3 border-b px-4 py-3"
        >
            <div class="min-w-0">
                <h2 class="font-display text-base leading-6 font-semibold">
                    {{ title }}
                </h2>
                <p v-if="description" class="text-xs text-muted-foreground">
                    {{ description }}
                </p>
            </div>
            <Button
                v-if="!empty && !loading && !error"
                variant="ghost"
                size="sm"
                class="shrink-0"
                :aria-pressed="asTable"
                data-test="chart-table-toggle"
                @click="asTable = !asTable"
            >
                <component
                    :is="asTable ? ChartNoAxesColumn : Table2"
                    aria-hidden="true"
                />
                {{
                    asTable
                        ? t('scampause.charts.show_chart')
                        : t('scampause.charts.show_table')
                }}
            </Button>
        </header>
        <div class="h-[240px] px-3 py-3 md:h-[280px]">
            <Skeleton v-if="loading" class="size-full" />
            <ErrorState
                v-else-if="error"
                :message="t('scampause.charts.error')"
                retryable
                :retrying="retrying"
                @retry="$emit('retry')"
            />
            <EmptyState
                v-else-if="empty"
                :icon="ChartNoAxesColumn"
                tone="muted"
                compact
                :title="t('scampause.charts.empty_title')"
                :description="t('scampause.charts.empty_body')"
            />
            <div v-else-if="asTable" class="size-full overflow-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        {{
                            summary
                        }}
                    </caption>
                    <thead class="sticky top-0 bg-card">
                        <tr class="border-b">
                            <th
                                v-for="(column, index) in columns"
                                :key="column"
                                scope="col"
                                :class="[
                                    'px-2 py-1.5 text-xs font-medium text-muted-foreground',
                                    index === 0 ? 'text-left' : 'text-right',
                                ]"
                            >
                                {{ column }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(row, rowIndex) in rows" :key="rowIndex">
                            <td
                                v-for="(cell, index) in row"
                                :key="index"
                                :class="[
                                    'px-2 py-1.5',
                                    index === 0
                                        ? 'text-left'
                                        : 'text-right font-mono tabular-nums',
                                ]"
                            >
                                {{ cell }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ThemedChart v-else :build="build" :label="summary" height="100%" />
        </div>
    </section>
</template>
