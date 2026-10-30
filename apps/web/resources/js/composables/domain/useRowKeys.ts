import { onKeyStroke } from '@vueuse/core';
import { ref } from 'vue';
import type { Ref } from 'vue';

export function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    const tag = target.tagName;

    return (
        tag === 'INPUT' ||
        tag === 'TEXTAREA' ||
        tag === 'SELECT' ||
        target.isContentEditable ||
        target.closest('[role="dialog"]') !== null
    );
}

export function nextIndex(
    current: number,
    total: number,
    direction: 1 | -1,
): number {
    if (total <= 0) {
        return -1;
    }

    if (current < 0) {
        return direction === 1 ? 0 : total - 1;
    }

    return Math.min(total - 1, Math.max(0, current + direction));
}

export function useRowKeys(
    container: Ref<HTMLElement | null>,
    selector = '[data-row-nav]',
): Ref<number> {
    const active = ref(-1);

    const move = (event: KeyboardEvent, direction: 1 | -1) => {
        if (
            isTypingTarget(event.target) ||
            event.metaKey ||
            event.ctrlKey ||
            event.altKey
        ) {
            return;
        }

        const rows = Array.from(
            container.value?.querySelectorAll<HTMLElement>(selector) ?? [],
        ).filter((row) => row.offsetParent !== null);

        if (!rows.length) {
            return;
        }

        const focused = rows.findIndex((row) => row === document.activeElement);
        active.value = nextIndex(
            focused >= 0 ? focused : active.value,
            rows.length,
            direction,
        );
        rows[active.value]?.focus();
        event.preventDefault();
    };

    onKeyStroke('j', (event) => move(event, 1));
    onKeyStroke('k', (event) => move(event, -1));

    return active;
}
