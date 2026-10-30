import { router, useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { isProcessing } from '@/lib/domain/status';
import { sp } from '@/lib/domain/routes';
import type { PipelineStatus } from '@/types/domain';

export const POLL_INTERVAL_MS = 2000;
export const POLL_LIMIT_MS = 180_000;

export function pipelineChanged(
    current: PipelineStatus,
    next: PipelineStatus,
): boolean {
    if (current.status !== next.status || current.revision !== next.revision) {
        return true;
    }

    return (
        Object.keys(next.stages) as Array<keyof PipelineStatus['stages']>
    ).some(
        (stage) => current.stages[stage]?.state !== next.stages[stage]?.state,
    );
}

export function usePipelinePolling(
    requestId: () => number,
    initial: () => PipelineStatus,
): { live: Ref<PipelineStatus>; timedOut: Ref<boolean>; failed: Ref<boolean> } {
    const live = ref<PipelineStatus>(initial()) as Ref<PipelineStatus>;
    const timedOut = ref(false);
    const failed = ref(false);
    const http = useHttp<Record<string, never>, PipelineStatus>({});
    let timer: ReturnType<typeof setTimeout> | null = null;
    let startedAt = Date.now();

    const stop = () => {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const tick = async (): Promise<void> => {
        if (Date.now() - startedAt > POLL_LIMIT_MS) {
            timedOut.value = true;
            stop();

            return;
        }

        try {
            const next = await http.get(sp.requests.status(requestId()).url);

            if (next) {
                failed.value = false;
                const changed = pipelineChanged(live.value, next);
                live.value = next;

                if (!isProcessing(next.status)) {
                    stop();
                    router.reload();

                    return;
                }

                if (changed && next.revision !== initial().revision) {
                    router.reload();
                }
            }
        } catch {
            failed.value = true;
        }

        timer = setTimeout(() => void tick(), POLL_INTERVAL_MS);
    };

    const start = () => {
        stop();
        timedOut.value = false;
        startedAt = Date.now();
        timer = setTimeout(() => void tick(), POLL_INTERVAL_MS);
    };

    watch(
        initial,
        (value) => {
            live.value = value;

            if (isProcessing(value.status)) {
                if (!timer) {
                    start();
                }
            } else {
                stop();
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(stop);

    return { live, timedOut, failed };
}
