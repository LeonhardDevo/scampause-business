import {
    axisTooltip,
    baseGrid,
    categoryAxis,
    legend,
    riskDecals,
    valueAxis,
} from '@/lib/charts';
import { compactMoney, formatMinor } from '@/lib/domain/money';
import { activeLocale, INTL_LOCALE, t } from '@/lib/i18n';
import type { ChartTheme } from '@/lib/theme';
import type { RiskWeek } from '@/types/domain';

export const RISK_ORDER = ['critical', 'high', 'medium', 'low'] as const;

export function weekLabel(week: string): string {
    const date = new Date(`${week}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
        return week;
    }

    return new Intl.DateTimeFormat(INTL_LOCALE[activeLocale()], {
        day: 'numeric',
        month: 'short',
    }).format(date);
}

export function monthLabel(month: string): string {
    const date = new Date(
        `${month.length === 7 ? `${month}-01` : month}T00:00:00`,
    );

    if (Number.isNaN(date.getTime())) {
        return month;
    }

    return new Intl.DateTimeFormat(INTL_LOCALE[activeLocale()], {
        month: 'short',
        year: '2-digit',
    }).format(date);
}

export function riskWeeksEmpty(weeks: RiskWeek[]): boolean {
    return weeks.every(
        (week) => week.low + week.medium + week.high + week.critical === 0,
    );
}

export function riskWeeksChart(theme: ChartTheme, weeks: RiskWeek[]) {
    const decals = riskDecals();
    const colors = {
        critical: theme.risk.critical,
        high: theme.risk.high,
        medium: theme.risk.medium,
        low: theme.risk.low,
    };

    return {
        aria: { enabled: true, decal: { show: true } },
        grid: baseGrid(),
        legend: legend(theme),
        tooltip: axisTooltip(theme),
        xAxis: categoryAxis(
            theme,
            weeks.map((week) => weekLabel(week.week)),
        ),
        yAxis: valueAxis(theme),
        series: RISK_ORDER.map((level, index) => ({
            type: 'bar',
            stack: 'risk',
            name: t(`ui.risk.levels.${level}`),
            barMaxWidth: 28,
            itemStyle: { color: colors[level], decal: decals[index] },
            emphasis: { focus: 'series' },
            data: weeks.map((week) => week[level]),
        })),
    };
}

export function moneyColumnsChart(
    theme: ChartTheme,
    labels: string[],
    series: Array<{
        name: string;
        color: string;
        data: number[];
        stack?: string;
    }>,
    currency: string,
) {
    const locale = activeLocale();

    return {
        grid: baseGrid(),
        legend: legend(theme),
        tooltip: {
            ...axisTooltip(theme),
            valueFormatter: (value: number) =>
                formatMinor(Math.round(value), currency, locale),
        },
        xAxis: categoryAxis(theme, labels),
        yAxis: valueAxis(theme, (value: number) =>
            compactMoney(value, currency, INTL_LOCALE[locale]),
        ),
        series: series.map((item) => ({
            type: 'bar',
            name: item.name,
            stack: item.stack,
            barMaxWidth: 26,
            itemStyle: { color: item.color, borderRadius: [3, 3, 0, 0] },
            data: item.data,
        })),
    };
}

export function horizontalBarsChart(
    theme: ChartTheme,
    items: Array<{ label: string; value: number; color?: string }>,
) {
    const sorted = [...items].sort((a, b) => a.value - b.value);

    return {
        grid: { ...baseGrid(), top: 8, right: 32 },
        tooltip: {
            ...axisTooltip(theme),
            axisPointer: { type: 'shadow' as const },
        },
        xAxis: { ...valueAxis(theme), minInterval: 1 },
        yAxis: {
            type: 'category' as const,
            data: sorted.map((item) => item.label),
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: {
                color: theme.mutedText,
                fontSize: 11,
                width: 150,
                overflow: 'truncate' as const,
            },
        },
        series: [
            {
                type: 'bar',
                barMaxWidth: 16,
                label: {
                    show: true,
                    position: 'right' as const,
                    color: theme.text,
                    fontSize: 11,
                },
                data: sorted.map((item) => ({
                    value: item.value,
                    itemStyle: {
                        color: item.color ?? theme.series[0],
                        borderRadius: [0, 3, 3, 0],
                    },
                })),
            },
        ],
    };
}

export function histogramChart(
    theme: ChartTheme,
    labels: string[],
    values: number[],
) {
    return {
        grid: baseGrid(),
        tooltip: {
            ...axisTooltip(theme),
            axisPointer: { type: 'shadow' as const },
        },
        xAxis: categoryAxis(theme, labels),
        yAxis: { ...valueAxis(theme), minInterval: 1 },
        series: [
            {
                type: 'bar',
                barCategoryGap: '8%',
                itemStyle: { color: theme.series[0] },
                label: {
                    show: true,
                    position: 'top' as const,
                    color: theme.mutedText,
                    fontSize: 11,
                },
                data: values,
            },
        ],
    };
}

export function donutChart(
    theme: ChartTheme,
    parts: Array<{ name: string; value: number; color: string }>,
    centerLabel: string,
) {
    const total = parts.reduce((sum, part) => sum + part.value, 0);

    return {
        tooltip: {
            trigger: 'item' as const,
            backgroundColor: theme.tooltipBackground,
            borderColor: theme.tooltipBorder,
            textStyle: { color: theme.text, fontSize: 12 },
        },
        legend: { ...legend(theme), top: 'bottom', right: 'center' },
        title: {
            text: String(total),
            subtext: centerLabel,
            left: 'center',
            top: '36%',
            textStyle: {
                color: theme.text,
                fontSize: 24,
                fontWeight: 600,
                fontFamily: theme.fontFamily,
            },
            subtextStyle: { color: theme.mutedText, fontSize: 11 },
        },
        series: [
            {
                type: 'pie',
                radius: ['55%', '75%'],
                center: ['50%', '45%'],
                avoidLabelOverlap: true,
                label: { show: false },
                itemStyle: { borderColor: theme.background, borderWidth: 2 },
                data: parts.map((part) => ({
                    name: part.name,
                    value: part.value,
                    itemStyle: { color: part.color },
                })),
            },
        ],
    };
}
