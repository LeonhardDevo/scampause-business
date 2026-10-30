<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { t } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import type { NavGroup, NavItem } from '@/types';

defineProps<{ groups: NavGroup[] }>();

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

function active(item: NavItem): boolean {
    return item.matchPrefix
        ? isCurrentOrParentUrl(item.href)
        : isCurrentUrl(item.href);
}
</script>

<template>
    <SidebarGroup
        v-for="group in groups"
        :key="group.label"
        class="px-3 py-1.5"
    >
        <SidebarGroupLabel
            class="h-7 px-2 eyebrow text-[0.6875rem] text-sidebar-muted-foreground"
        >
            {{ t(group.label) }}
        </SidebarGroupLabel>
        <SidebarMenu class="gap-0.5">
            <SidebarMenuItem
                v-for="item in group.items"
                :key="toUrl(item.href)"
                class="relative"
            >
                <span
                    v-if="active(item)"
                    class="absolute top-1.5 bottom-1.5 -left-3 w-0.5 rounded-r-sm bg-sidebar-primary"
                    aria-hidden="true"
                />
                <SidebarMenuButton
                    as-child
                    :is-active="active(item)"
                    :tooltip="t(item.title)"
                    class="text-sidebar-foreground data-[active=true]:font-semibold data-[active=true]:[&>svg]:text-sidebar-primary"
                >
                    <Link
                        :href="item.href"
                        prefetch
                        :aria-current="active(item) ? 'page' : undefined"
                    >
                        <component
                            :is="item.icon"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        <span>{{ t(item.title) }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuBadge
                    v-if="item.badge"
                    :class="[
                        'rounded-sm font-mono',
                        item.badgeTone === 'warning'
                            ? 'bg-hazard text-hazard-foreground'
                            : item.badgeTone === 'info'
                              ? 'bg-sidebar-primary text-sidebar-primary-foreground'
                              : 'text-sidebar-foreground',
                    ]"
                    :data-test="`nav-badge-${item.title}`"
                    >{{ item.badge }}</SidebarMenuBadge
                >
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
