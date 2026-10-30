<script setup lang="ts">
import AppLogo from '@/components/shell/AppLogo.vue';
import AppToaster from '@/components/shell/AppToaster.vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import SkipLink from '@/components/shell/SkipLink.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
</script>

<template>
    <SkipLink />
    <div class="relative flex min-h-svh flex-col bg-background">
        <div
            class="pointer-events-none absolute inset-x-0 top-0 h-[24rem] bg-grid-paper mask-fade-top"
            aria-hidden="true"
        />
        <header
            class="relative z-10 mx-auto flex w-full max-w-3xl items-center justify-between gap-3 px-5 py-4"
        >
            <AppLogo />
            <div class="flex items-center gap-1">
                <LocaleSwitcher />
                <ThemeToggle />
            </div>
        </header>
        <main
            id="main-content"
            tabindex="-1"
            class="relative z-10 mx-auto w-full max-w-xl flex-1 px-5 pb-12 focus:outline-none"
        >
            <slot />
        </main>
    </div>
    <AppToaster />
</template>
