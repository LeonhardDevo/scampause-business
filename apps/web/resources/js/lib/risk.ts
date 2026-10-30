import {
    CircleAlert,
    CircleDot,
    ShieldAlert,
    TriangleAlert,
} from '@lucide/vue';
import type { Component } from 'vue';

export type RiskLevel = 'critical' | 'high' | 'medium' | 'low';

export const RISK_LEVELS: readonly RiskLevel[] = [
    'critical',
    'high',
    'medium',
    'low',
] as const;

export const RISK_ICONS: Record<RiskLevel, Component> = {
    critical: ShieldAlert,
    high: TriangleAlert,
    medium: CircleAlert,
    low: CircleDot,
};

export const RISK_TEXT: Record<RiskLevel, string> = {
    critical: 'text-risk-critical',
    high: 'text-risk-high',
    medium: 'text-risk-medium',
    low: 'text-risk-low',
};

export const RISK_TINT: Record<RiskLevel, string> = {
    critical: 'bg-risk-critical-tint text-risk-critical',
    high: 'bg-risk-high-tint text-risk-high',
    medium: 'bg-risk-medium-tint text-risk-medium',
    low: 'bg-risk-low-tint text-risk-low',
};

export const RISK_STROKE: Record<RiskLevel, string> = {
    critical: 'stroke-risk-critical-fill',
    high: 'stroke-risk-high-fill',
    medium: 'stroke-risk-medium-fill',
    low: 'stroke-risk-low-fill',
};

export const RISK_FILL: Record<RiskLevel, string> = {
    critical: 'bg-risk-critical-fill',
    high: 'bg-risk-high-fill',
    medium: 'bg-risk-medium-fill',
    low: 'bg-risk-low-fill',
};

export function riskLevelFor(score: number): RiskLevel {
    if (score >= 75) {
        return 'critical';
    }

    if (score >= 50) {
        return 'high';
    }

    if (score >= 25) {
        return 'medium';
    }

    return 'low';
}

export function clampScore(score: number): number {
    if (!Number.isFinite(score)) {
        return 0;
    }

    return Math.min(100, Math.max(0, Math.round(score)));
}

export function severityBars(points: number): number {
    if (points >= 36) {
        return 4;
    }

    if (points >= 21) {
        return 3;
    }

    if (points >= 11) {
        return 2;
    }

    return 1;
}

export function maskAccount(value: string, visible = 4): string {
    const digits = value.replace(/\s+/g, '');

    if (digits.length <= visible) {
        return digits;
    }

    return `•••• ${digits.slice(-visible)}`;
}
