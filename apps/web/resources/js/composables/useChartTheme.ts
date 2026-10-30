import { onBeforeUnmount, onMounted, shallowRef } from 'vue';
import type { ShallowRef } from 'vue';
import { THEME_EVENT } from '@/composables/useAppearance';
import { CHART_FONT_PROBE, readChartTheme } from '@/lib/theme';
import type { ChartTheme } from '@/lib/theme';

export function useChartTheme(): ShallowRef<ChartTheme | null> {
    const theme = shallowRef<ChartTheme | null>(null);

    const refresh = () => {
        requestAnimationFrame(() => {
            theme.value = readChartTheme();
        });
    };

    let observer: MutationObserver | null = null;

    const fontsReady = async (): Promise<void> => {
        if (!('fonts' in document)) {
            return;
        }

        try {
            await document.fonts.ready;
            await document.fonts.load(CHART_FONT_PROBE, 'Ж');
        } catch {
            return;
        }
    };

    onMounted(() => {
        void fontsReady().then(() => {
            theme.value = readChartTheme();
        });
        document.addEventListener(THEME_EVENT, refresh);
        observer = new MutationObserver(refresh);
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    });

    onBeforeUnmount(() => {
        document.removeEventListener(THEME_EVENT, refresh);
        observer?.disconnect();
    });

    return theme;
}
