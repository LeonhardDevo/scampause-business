<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AuthConsole from '@/components/brand/AuthConsole.vue';
import AppLogo from '@/components/shell/AppLogo.vue';
import CookieNotice from '@/components/legal/CookieNotice.vue';
import AppToaster from '@/components/shell/AppToaster.vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import SkipLink from '@/components/shell/SkipLink.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';

withDefaults(defineProps<{ title?: string; description?: string }>(), {
    title: '',
    description: '',
});

const page = usePage();
</script>

<template>
    <SkipLink />
    <div
        class="grid min-h-svh grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]"
    >
        <div class="relative flex min-h-svh flex-col px-4 py-5 sm:px-10">
            <div
                class="pointer-events-none absolute inset-x-0 top-0 h-72 bg-grid-paper mask-fade-top lg:hidden"
                aria-hidden="true"
            />
            <header class="relative flex items-center justify-between gap-3">
                <Link
                    :href="endpoints.home()"
                    class="rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                    <AppLogo />
                </Link>
                <div class="flex items-center gap-1">
                    <LocaleSwitcher />
                    <ThemeToggle />
                </div>
            </header>

            <main
                id="main-content"
                tabindex="-1"
                class="relative mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-10 focus:outline-none"
            >
                <div class="mb-8 space-y-2">
                    <p
                        class="flex items-center gap-2 eyebrow text-muted-foreground"
                    >
                        <span
                            class="inline-block h-3 w-0.5 bg-hazard"
                            aria-hidden="true"
                        />
                        {{ page.props.name }}
                    </p>
                    <h1
                        v-if="title"
                        class="font-display text-[1.75rem] leading-tight font-semibold tracking-[-0.01em]"
                    >
                        {{ t(title) }}
                    </h1>
                    <p v-if="description" class="text-sm text-muted-foreground">
                        {{ t(description) }}
                    </p>
                </div>
                <slot />
            </main>

            <footer
                class="relative flex flex-col items-center gap-2 text-xs text-muted-foreground"
            >
                <nav
                    :aria-label="t('marketing.footer.legal')"
                    class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1"
                >
                    <Link
                        :href="endpoints.legal('terms')"
                        class="hover:text-foreground hover:underline"
                    >
                        {{ t('ui.legal.terms') }}
                    </Link>
                    <Link
                        :href="endpoints.legal('privacy')"
                        class="hover:text-foreground hover:underline"
                    >
                        {{ t('ui.legal.privacy') }}
                    </Link>
                    <Link
                        :href="endpoints.legal('cookies')"
                        class="hover:text-foreground hover:underline"
                    >
                        {{ t('ui.legal.docs.cookies') }}
                    </Link>
                    <Link
                        :href="endpoints.security()"
                        class="hover:text-foreground hover:underline"
                    >
                        {{ t('ui.legal.security') }}
                    </Link>
                </nav>
                <p class="text-center lg:hidden">
                    {{ t('marketing.footer.disclaimer') }}
                </p>
            </footer>
        </div>
        <aside
            class="hidden lg:block"
            :aria-label="t('marketing.auth.preview_label')"
        >
            <div class="sticky top-0 h-svh">
                <AuthConsole />
            </div>
        </aside>
    </div>
    <AppToaster />
    <CookieNotice />
</template>
