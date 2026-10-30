import type { ChartTheme } from '@/lib/theme';
import { withAlpha } from '@/lib/theme';

export function baseGrid() {
    return {
        left: 8,
        right: 8,
        top: 32,
        bottom: 4,
        outerBoundsMode: 'same' as const,
        outerBoundsContain: 'axisLabel' as const,
    };
}

export function axisTooltip(theme: ChartTheme) {
    return {
        trigger: 'axis' as const,
        backgroundColor: theme.tooltipBackground,
        borderColor: theme.tooltipBorder,
        borderWidth: 1,
        padding: [8, 12],
        textStyle: {
            color: theme.text,
            fontFamily: theme.fontFamily,
            fontSize: 12,
        },
        axisPointer: {
            type: 'line' as const,
            lineStyle: { color: theme.axis, type: 'dashed' as const, width: 1 },
        },
        extraCssText:
            'border-radius: 8px; box-shadow: 0 6px 16px -4px rgba(11,18,32,.18);',
    };
}

export function categoryAxis(theme: ChartTheme, data: string[]) {
    return {
        type: 'category' as const,
        data,
        boundaryGap: true,
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: theme.mutedText, fontSize: 11, hideOverlap: true },
    };
}

export function valueAxis(
    theme: ChartTheme,
    formatter?: (value: number) => string,
) {
    return {
        type: 'value' as const,
        splitLine: { lineStyle: { color: theme.grid } },
        axisLabel: {
            color: theme.mutedText,
            fontSize: 11,
            ...(formatter ? { formatter } : {}),
        },
    };
}

export function legend(theme: ChartTheme) {
    return {
        top: 0,
        right: 0,
        icon: 'rect',
        itemWidth: 10,
        itemHeight: 10,
        textStyle: {
            color: theme.mutedText,
            fontSize: 12,
            fontFamily: theme.fontFamily,
        },
    };
}

export function riskSeriesColors(theme: ChartTheme): string[] {
    return [
        theme.risk.critical,
        theme.risk.high,
        theme.risk.medium,
        theme.risk.low,
    ];
}

export function riskDecals() {
    return [
        {
            symbol: 'rect',
            rotation: Math.PI / 4,
            dashArrayX: [1, 0],
            dashArrayY: [2, 4],
            color: 'rgba(255, 255, 255, 0.45)',
        },
        {
            symbol: 'circle',
            symbolSize: 0.6,
            dashArrayX: [[4, 4]],
            dashArrayY: [4, 4],
            color: 'rgba(255, 255, 255, 0.45)',
        },
        { symbol: 'none' },
        { symbol: 'none' },
    ];
}

export function areaFill(color: string) {
    return {
        color: {
            type: 'linear' as const,
            x: 0,
            y: 0,
            x2: 0,
            y2: 1,
            colorStops: [
                { offset: 0, color: withAlpha(color, 0.28) },
                { offset: 1, color: withAlpha(color, 0.02) },
            ],
        },
    };
}
