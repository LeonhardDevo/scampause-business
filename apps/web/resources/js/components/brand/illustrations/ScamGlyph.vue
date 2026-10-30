<script setup lang="ts">
import type { ScamKind } from '@/types/brand';

defineProps<{ kind: ScamKind; class?: string }>();

const qrCells = [
    [0, 0],
    [2, 0],
    [3, 1],
    [1, 2],
    [4, 2],
    [0, 3],
    [2, 3],
    [3, 4],
    [5, 4],
    [1, 5],
    [4, 5],
    [5, 1],
];
</script>

<template>
    <svg
        viewBox="0 0 160 88"
        fill="none"
        :class="$props.class"
        aria-hidden="true"
        focusable="false"
        stroke-width="1.5"
    >
        <template v-if="kind === 'invoice'">
            <rect
                x="36.75"
                y="6.75"
                width="62.5"
                height="76.5"
                rx="4"
                class="fill-card stroke-border"
            />
            <rect
                x="46"
                y="17"
                width="26"
                height="5"
                rx="1"
                class="fill-foreground/70"
            />
            <rect
                x="46"
                y="30"
                width="44"
                height="3"
                rx="1"
                class="fill-muted-foreground/30"
            />
            <rect
                x="46"
                y="38"
                width="36"
                height="3"
                rx="1"
                class="fill-muted-foreground/30"
            />
            <rect
                x="46"
                y="46"
                width="40"
                height="3"
                rx="1"
                class="fill-muted-foreground/30"
            />
            <path d="M46 60.5h44" class="stroke-border" />
            <rect
                x="70"
                y="65"
                width="20"
                height="6"
                rx="1"
                class="fill-foreground/80"
            />
            <g transform="rotate(-12 112 34)">
                <rect
                    x="88.75"
                    y="24.75"
                    width="50.5"
                    height="20.5"
                    rx="3"
                    class="fill-risk-high-tint stroke-risk-high-fill"
                    stroke-width="2"
                />
                <rect
                    x="95"
                    y="31"
                    width="38"
                    height="3"
                    rx="1"
                    class="fill-risk-high-fill"
                />
                <rect
                    x="100"
                    y="37"
                    width="28"
                    height="3"
                    rx="1"
                    class="fill-risk-high-fill/60"
                />
            </g>
        </template>

        <template v-else-if="kind === 'vendor'">
            <rect
                x="14.75"
                y="14.75"
                width="130.5"
                height="22.5"
                rx="4"
                class="fill-card stroke-border"
            />
            <text
                x="22"
                y="30"
                class="fill-muted-foreground font-mono text-[8.5px]"
            >
                blueridgepack.com
            </text>
            <rect
                x="14.75"
                y="48.75"
                width="130.5"
                height="22.5"
                rx="4"
                class="fill-risk-critical-tint stroke-risk-critical-fill"
            />
            <text
                x="22"
                y="64"
                class="fill-risk-critical font-mono text-[8.5px] font-semibold"
            >
                blueridge-pack.com
            </text>
            <circle cx="132" cy="60" r="6" class="fill-risk-critical-fill" />
            <path
                d="M132 56.5v4M132 62.8v.4"
                class="stroke-card"
                stroke-width="1.75"
                stroke-linecap="round"
            />
        </template>

        <template v-else-if="kind === 'payroll'">
            <rect
                x="10.75"
                y="18.75"
                width="58.5"
                height="50.5"
                rx="5"
                class="fill-card stroke-border"
            />
            <circle cx="26" cy="36" r="7" class="fill-muted stroke-border" />
            <rect
                x="38"
                y="31"
                width="22"
                height="4"
                rx="1"
                class="fill-foreground/70"
            />
            <rect
                x="38"
                y="39"
                width="16"
                height="3"
                rx="1"
                class="fill-muted-foreground/40"
            />
            <rect
                x="18"
                y="54"
                width="42"
                height="5"
                rx="1"
                class="fill-muted-foreground/25"
            />
            <path
                d="M76 44h20m-6-6 6 6-6 6"
                class="stroke-hazard"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <rect
                x="102.75"
                y="26.75"
                width="46.5"
                height="34.5"
                rx="5"
                class="fill-risk-critical-tint stroke-risk-critical-fill"
            />
            <rect
                x="110"
                y="34"
                width="12"
                height="9"
                rx="1.5"
                class="fill-risk-critical-fill/60"
            />
            <rect
                x="110"
                y="49"
                width="32"
                height="4"
                rx="1"
                class="fill-risk-critical-fill"
            />
        </template>

        <template v-else>
            <g transform="translate(18 10)">
                <rect
                    x="0.75"
                    y="0.75"
                    width="66.5"
                    height="66.5"
                    rx="5"
                    class="fill-card stroke-border"
                />
                <rect
                    x="7"
                    y="7"
                    width="16"
                    height="16"
                    rx="2"
                    class="stroke-foreground/80"
                    stroke-width="3"
                />
                <rect
                    x="45"
                    y="7"
                    width="16"
                    height="16"
                    rx="2"
                    class="stroke-foreground/80"
                    stroke-width="3"
                />
                <rect
                    x="7"
                    y="45"
                    width="16"
                    height="16"
                    rx="2"
                    class="stroke-foreground/80"
                    stroke-width="3"
                />
                <rect
                    v-for="([cx, cy], index) in qrCells"
                    :key="index"
                    :x="26 + cx * 5"
                    :y="26 + cy * 5"
                    width="4"
                    height="4"
                    class="fill-foreground/70"
                />
                <path
                    d="M4 64 64 4"
                    class="stroke-risk-critical-fill"
                    stroke-width="3"
                    stroke-linecap="round"
                />
            </g>
            <g transform="translate(100 18)">
                <circle
                    cx="22"
                    cy="26"
                    r="20.25"
                    class="fill-card stroke-border"
                />
                <path
                    d="M22 14v12l8 5"
                    class="stroke-hazard"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </g>
        </template>
    </svg>
</template>
