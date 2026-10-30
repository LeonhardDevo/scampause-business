<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Menu } from '@lucide/vue';
import { useWindowScroll } from '@vueuse/core';
import { computed, ref } from 'vue';
import type { MarketingLink } from '@/components/marketing/types';
import AppLogo from '@/components/shell/AppLogo.vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { cn, toUrl } from '@/lib/utils';
import { login, register } from '@/routes';

const props = withDefaults(
    defineProps<{ links: MarketingLink[]; canRegister?: boolean }>(),
    {
        canRegister: true,
    },
);

const page = usePage();
const { y } = useWindowScroll();
const scrolled = computed(() => y.value > 8);
const mobileOpen = ref(false);
const { isCurrentUrl } = useCurrentUrl();

const isAuthed = computed(() => Boolean(page.props.auth?.user));

function hrefOf(link: MarketingLink): string {
    return typeof link.href === 'string' ? link.href : toUrl(link.href);
}

const items = computed(() =>
    props.links.map((link) => ({
        ...link,
        url: hrefOf(link),
        active: !link.anchor && isCurrentUrl(hrefOf(link)),
    })),
);
</script>

<template>
    <header class="sticky top-0 z-40 px-3 pt-3 sm:px-4 sm:pt-4">
        <div
            :class="
                cn(
                    'mx-auto flex h-14 max-w-[75rem] items-center gap-3 rounded-xl border glass px-3 transition-shadow duration-200 sm:px-4',
                    scrolled ? 'shadow-md' : 'shadow-none',
                )
            "
        >
            <Link
                :href="endpoints.home()"
                class="shrink-0 rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <AppLogo />
            </Link>

            <nav
                :aria-label="t('marketing.nav.label')"
                class="ml-4 hidden items-center gap-0.5 lg:flex"
            >
                <component
                    :is="item.anchor ? 'a' : Link"
                    v-for="item in items"
                    :key="item.label"
                    :href="item.url"
                    :aria-current="item.active ? 'page' : undefined"
                    :class="
                        cn(
                            'relative rounded-md px-3 py-2 text-sm font-medium transition-colors duration-100 hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                            item.active
                                ? 'text-foreground after:absolute after:inset-x-3 after:-bottom-px after:h-0.5 after:bg-hazard'
                                : 'text-muted-foreground',
                        )
                    "
                >
                    {{ t(item.label) }}
                </component>
            </nav>

            <div class="ml-auto flex items-center gap-1">
                <LocaleSwitcher />
                <ThemeToggle />
                <template v-if="isAuthed">
                    <Button
                        as-child
                        size="sm"
                        class="ml-1 hidden h-9 sm:inline-flex"
                    >
                        <Link :href="endpoints.dashboard()">
                            {{ t('marketing.nav.dashboard') }}
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                </template>
                <template v-else>
                    <Button
                        as-child
                        variant="ghost"
                        size="sm"
                        class="hidden h-9 md:inline-flex"
                    >
                        <Link :href="login()">{{
                            t('marketing.nav.login')
                        }}</Link>
                    </Button>
                    <Button
                        v-if="canRegister"
                        as-child
                        size="sm"
                        class="ml-1 hidden h-9 sm:inline-flex"
                    >
                        <Link :href="register()" data-test="nav-get-started">
                            {{ t('marketing.nav.get_started') }}
                        </Link>
                    </Button>
                </template>

                <Sheet v-model:open="mobileOpen">
                    <SheetTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="lg:hidden"
                            :aria-label="t('marketing.nav.open_menu')"
                        >
                            <Menu class="size-5" aria-hidden="true" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" class="w-full max-w-xs p-0">
                        <SheetHeader class="border-b px-5 py-4 text-left">
                            <SheetTitle><AppLogo /></SheetTitle>
                            <SheetDescription class="sr-only">{{
                                t('marketing.nav.menu')
                            }}</SheetDescription>
                        </SheetHeader>
                        <nav
                            :aria-label="t('marketing.nav.label')"
                            class="flex flex-col gap-1 p-3"
                        >
                            <SheetClose
                                v-for="item in items"
                                :key="item.label"
                                as-child
                            >
                                <component
                                    :is="item.anchor ? 'a' : Link"
                                    :href="item.url"
                                    class="flex min-h-11 items-center rounded-md px-3 text-base font-medium hover:bg-accent"
                                >
                                    {{ t(item.label) }}
                                </component>
                            </SheetClose>
                        </nav>
                        <div class="grid gap-2 border-t px-5 py-4">
                            <Button v-if="isAuthed" as-child size="lg">
                                <Link :href="endpoints.dashboard()">{{
                                    t('marketing.nav.dashboard')
                                }}</Link>
                            </Button>
                            <Button
                                v-if="!isAuthed"
                                as-child
                                variant="outline"
                                size="lg"
                            >
                                <Link :href="login()">{{
                                    t('marketing.nav.login')
                                }}</Link>
                            </Button>
                            <Button
                                v-if="!isAuthed && canRegister"
                                as-child
                                size="lg"
                            >
                                <Link :href="register()">{{
                                    t('marketing.nav.get_started')
                                }}</Link>
                            </Button>
                        </div>
                    </SheetContent>
                </Sheet>
            </div>
        </div>
    </header>
</template>
