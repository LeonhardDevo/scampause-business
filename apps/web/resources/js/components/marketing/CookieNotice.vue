<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Cookie } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import {
    COOKIE_NOTICE_NAME,
    hasCookie,
    noticeCookieValue,
} from '@/lib/cookie-notice';

const visible = ref(false);

onMounted(() => {
    visible.value = !hasCookie(document.cookie, COOKIE_NOTICE_NAME);
});

function dismiss(): void {
    document.cookie = noticeCookieValue(window.location.protocol === 'https:');
    visible.value = false;
}
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-standard motion-reduce:transition-none"
        enter-from-class="translate-y-2 opacity-0"
        leave-active-class="transition duration-150 ease-exit motion-reduce:transition-none"
        leave-to-class="translate-y-2 opacity-0"
    >
        <aside
            v-if="visible"
            class="fixed inset-x-3 bottom-3 z-50 flex items-start gap-3 rounded-lg border bg-popover p-3 text-popover-foreground shadow-lg sm:right-auto sm:bottom-4 sm:left-4 sm:max-w-md"
            :aria-label="t('marketing.cookie.label')"
            data-test="cookie-notice"
        >
            <Cookie
                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <div
                class="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center sm:gap-3"
            >
                <p class="min-w-0 flex-1 text-xs leading-relaxed text-pretty">
                    {{ t('marketing.cookie.text') }}
                    <Link
                        :href="endpoints.legal('cookies')"
                        class="font-medium text-primary underline underline-offset-2"
                        >{{ t('marketing.cookie.link') }}</Link
                    >
                </p>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8 self-start sm:self-center"
                    data-test="cookie-notice-dismiss"
                    @click="dismiss"
                >
                    {{ t('marketing.cookie.dismiss') }}
                </Button>
            </div>
        </aside>
    </Transition>
</template>
