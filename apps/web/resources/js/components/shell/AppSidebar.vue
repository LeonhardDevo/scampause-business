<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';
import NavMain from '@/components/shell/NavMain.vue';
import NavUser from '@/components/shell/NavUser.vue';
import AppLogo from '@/components/shell/AppLogo.vue';
import WorkspaceSwitcher from '@/components/shell/WorkspaceSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarRail,
} from '@/components/ui/sidebar';
import { useCan } from '@/composables/useCan';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { appNavigation } from '@/lib/navigation';

const page = usePage();
const { can } = useCan();

const groups = computed(() =>
    appNavigation(
        can,
        Boolean(page.props.workspace),
        page.props.scampause?.counts ?? null,
    ),
);

const showUpgrade = computed(
    () =>
        page.props.workspace?.plan === 'free' &&
        can('billing.manage', 'workspace.manage'),
);

const home = computed(() =>
    page.props.workspace ? endpoints.dashboard() : endpoints.home(),
);
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="sidebar"
        class="border-r-0"
        :aria-label="t('ui.nav.main_navigation')"
    >
        <SidebarHeader class="gap-3 px-3 pt-4 pb-2">
            <Link
                :href="home"
                class="flex h-9 items-center rounded-md px-1 text-sidebar-accent-foreground group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sidebar-ring"
            >
                <AppLogo
                    inverse
                    class="group-data-[collapsible=icon]:[&>span:last-child]:hidden"
                />
            </Link>
            <WorkspaceSwitcher />
        </SidebarHeader>

        <SidebarContent class="scrollbar-thin gap-1 pt-1">
            <NavMain :groups="groups" />
        </SidebarContent>

        <SidebarFooter class="gap-2 px-3 pb-3">
            <div
                v-if="showUpgrade"
                class="rounded-lg border border-sidebar-border p-3 group-data-[collapsible=icon]:hidden"
            >
                <p
                    class="flex items-center gap-2 eyebrow text-sidebar-muted-foreground"
                >
                    <span
                        class="inline-block h-3 w-1 rounded-[1px] hazard-stripe"
                        aria-hidden="true"
                    />
                    {{ t('ui.upgrade.title') }}
                </p>
                <p
                    class="mt-1.5 text-xs leading-relaxed text-sidebar-foreground"
                >
                    {{ t('ui.upgrade.body') }}
                </p>
                <Link
                    :href="endpoints.settings.billing.show()"
                    class="mt-2.5 inline-flex h-8 items-center gap-1 rounded-md bg-sidebar-primary px-3 text-xs font-semibold text-sidebar-primary-foreground transition-colors duration-100 hover:bg-sidebar-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sidebar-ring pointer-coarse:h-11"
                >
                    {{ t('ui.upgrade.cta') }}
                    <ArrowUpRight class="size-3.5" aria-hidden="true" />
                </Link>
            </div>
            <NavUser />
        </SidebarFooter>
        <SidebarRail />
    </Sidebar>
</template>
