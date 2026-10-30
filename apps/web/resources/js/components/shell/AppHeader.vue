<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ScanSearch, Search } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import Kbd from '@/components/common/Kbd.vue';
import Breadcrumbs from '@/components/shell/Breadcrumbs.vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import NotificationsPopover from '@/components/shell/NotificationsPopover.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useCan } from '@/composables/useCan';
import {
    isApplePlatform,
    useCommandPalette,
} from '@/composables/useCommandPalette';
import { sp } from '@/lib/domain/routes';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});

const { show } = useCommandPalette();
const page = usePage();
const { can } = useCan();
const apple = ref(false);

onMounted(() => {
    apple.value = isApplePlatform();
});
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 border-b glass px-3 md:px-6"
        data-print-hide
    >
        <SidebarTrigger class="-ml-1" />
        <Separator orientation="vertical" class="mr-1 hidden !h-5 md:block" />
        <div class="min-w-0 flex-1">
            <Breadcrumbs v-if="breadcrumbs.length" :breadcrumbs="breadcrumbs" />
        </div>

        <Button
            variant="outline"
            class="hidden h-9 w-64 justify-start gap-2 border-border bg-muted/50 px-3 font-normal text-muted-foreground lg:inline-flex"
            data-test="command-palette-trigger"
            @click="show"
        >
            <Search class="size-4 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1 truncate text-left">{{
                t('ui.shell.search_placeholder')
            }}</span>
            <span class="flex shrink-0 items-center gap-0.5" aria-hidden="true">
                <Kbd>{{ apple ? '⌘' : 'Ctrl' }}</Kbd>
                <Kbd>K</Kbd>
            </span>
        </Button>
        <Button
            variant="ghost"
            size="icon"
            class="lg:hidden"
            :aria-label="t('ui.shell.search_placeholder')"
            @click="show"
        >
            <Search class="size-[1.15rem]" aria-hidden="true" />
        </Button>

        <Button
            v-if="page.props.workspace && can('payment_requests.create')"
            as-child
            size="sm"
            class="h-9"
            data-test="header-check-payment"
        >
            <Link :href="sp.requests.create()">
                <ScanSearch aria-hidden="true" />
                <span class="hidden sm:inline">{{
                    t('scampause.actions.check_payment')
                }}</span>
                <span class="sr-only sm:hidden">{{
                    t('scampause.actions.check_payment')
                }}</span>
            </Link>
        </Button>

        <NotificationsPopover />
        <LocaleSwitcher />
        <ThemeToggle />
    </header>
</template>
