import { describe, expect, it, vi } from 'vitest';
import { useStableNow } from '@/composables/useStableNow';

const props: { now?: string } = {};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props }),
}));

describe('useStableNow', () => {
    it('uses the server time before the component is mounted', () => {
        props.now = '2026-01-02T03:04:05Z';

        expect(useStableNow().value.toISOString()).toBe(
            '2026-01-02T03:04:05.000Z',
        );
    });

    it('falls back to the live clock without a server time', () => {
        delete props.now;
        const before = Date.now();

        expect(useStableNow().value.getTime()).toBeGreaterThanOrEqual(
            before - 1000,
        );
    });
});
