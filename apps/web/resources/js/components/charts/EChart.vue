<script setup lang="ts">
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import {
    AriaComponent,
    DatasetComponent,
    GridComponent,
    LegendComponent,
    TitleComponent,
    TooltipComponent,
} from 'echarts/components';
import type { EChartsCoreOption } from 'echarts/core';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import VChart from 'vue-echarts';

use([
    CanvasRenderer,
    LineChart,
    BarChart,
    PieChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    DatasetComponent,
    TitleComponent,
    AriaComponent,
]);

defineProps<{ option: EChartsCoreOption }>();
</script>

<template>
    <VChart :option="option" autoresize :update-options="{ notMerge: true }" />
</template>
