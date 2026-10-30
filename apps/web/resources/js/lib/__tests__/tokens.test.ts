import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { CHART_SERIES_TOKENS } from '@/lib/theme';

const here = path.dirname(fileURLToPath(import.meta.url));
const cssDir = path.resolve(here, '../../../css');
const brand = fs.readFileSync(path.join(cssDir, 'brand.css'), 'utf8');
const app = fs.readFileSync(path.join(cssDir, 'app.css'), 'utf8');
const fonts = fs.readFileSync(path.join(cssDir, 'fonts.css'), 'utf8');

function block(selector: RegExp): string {
    const match = brand.match(selector);

    return match?.[1] ?? '';
}

const light = block(/:root,\s*\.light\s*\{([\s\S]*?)\n\}/);
const dark = block(/\.dark\s*\{([\s\S]*?)\n\}/);

const themed = [
    'background',
    'foreground',
    'card',
    'popover',
    'muted',
    'muted-foreground',
    'border',
    'input',
    'ring',
    'primary',
    'primary-foreground',
    'secondary',
    'accent',
    'destructive',
    'success',
    'warning',
    'info',
    'hazard-ink',
    'risk-critical',
    'risk-critical-tint',
    'risk-critical-fill',
    'risk-high',
    'risk-high-tint',
    'risk-high-fill',
    'risk-medium',
    'risk-medium-tint',
    'risk-medium-fill',
    'risk-low',
    'risk-low-tint',
    'risk-low-fill',
    'grid-line',
    'redaction',
    'sidebar',
    'sidebar-accent',
    'sidebar-border',
    ...CHART_SERIES_TOKENS.map((token) => token.slice(2)),
];

describe('brand tokens', () => {
    it('defines every themed token for light and dark', () => {
        for (const token of themed) {
            expect(light, `light --${token}`).toMatch(
                new RegExp(`--${token}:\\s*oklch\\(`),
            );
            expect(dark, `dark --${token}`).toMatch(
                new RegExp(`--${token}:\\s*oklch\\(`),
            );
        }
    });

    it('keeps the hazard mark identical in both themes', () => {
        expect(light).toContain('--hazard: oklch(0.769 0.165 70.1)');
        expect(dark).not.toMatch(/--hazard:\s/);
    });

    it('maps the risk and chart tokens into Tailwind utilities', () => {
        for (const token of [
            'hazard',
            'risk-critical-fill',
            'risk-low-tint',
            'grid-line',
            'chart-8',
            'sidebar-muted-foreground',
        ]) {
            expect(app).toContain(`--color-${token}: var(--${token});`);
        }
    });

    it('loads IBM Plex from local packages only', () => {
        expect(fonts).toContain('@fontsource-variable/ibm-plex-sans/wdth.css');
        expect(fonts).toContain('@fontsource/ibm-plex-mono/400.css');
        expect(`${fonts}${brand}${app}`).not.toMatch(/https?:\/\//);
        expect(brand).toContain("'IBM Plex Sans Variable'");
        expect(brand).toContain("'IBM Plex Mono'");
    });
});
