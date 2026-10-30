<script setup lang="ts">
import { Download, FileText, Fingerprint, ImageOff } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import CopyButton from '@/components/common/CopyButton.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { enumLabel } from '@/lib/domain/status';
import { t, tc } from '@/lib/i18n';
import type { FileDto, PreviewPage } from '@/types/domain';

defineProps<{ files: FileDto[]; previews: PreviewPage[] }>();

const fmt = useFormat();
const pending = ref<FileDto | null>(null);
const confirmOpen = ref(false);
const broken = ref<Set<number>>(new Set());

function ask(file: FileDto): void {
    pending.value = file;
    confirmOpen.value = true;
}

function download(): void {
    if (pending.value?.download_url) {
        window.location.assign(pending.value.download_url);
    }

    confirmOpen.value = false;
}

function markBroken(page: number): void {
    broken.value = new Set([...broken.value, page]);
}
</script>

<template>
    <div class="space-y-6" data-test="case-files">
        <section v-if="previews.length" class="space-y-2">
            <h3 class="eyebrow text-[0.6875rem] text-muted-foreground">
                {{ t('scampause.files.previews') }}
            </h3>
            <p class="text-sm text-pretty text-muted-foreground">
                {{ t('scampause.files.previews_hint') }}
            </p>
            <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <li
                    v-for="preview in previews"
                    :key="preview.page"
                    class="overflow-hidden rounded-lg border bg-muted/40"
                >
                    <div
                        v-if="broken.has(preview.page)"
                        class="flex aspect-[3/4] items-center justify-center text-sm text-muted-foreground"
                    >
                        <ImageOff class="mr-2 size-4" aria-hidden="true" />
                        {{ t('scampause.files.preview_expired') }}
                    </div>
                    <img
                        v-else
                        :src="preview.url"
                        :alt="
                            t('scampause.files.page_alt', {
                                page: preview.page,
                            })
                        "
                        class="aspect-[3/4] w-full bg-white object-contain"
                        loading="lazy"
                        referrerpolicy="no-referrer"
                        @error="markBroken(preview.page)"
                    />
                    <p
                        class="border-t bg-card px-2 py-1 text-xs text-muted-foreground"
                    >
                        {{ t('scampause.files.page', { page: preview.page }) }}
                    </p>
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h3 class="eyebrow text-[0.6875rem] text-muted-foreground">
                {{ t('scampause.files.originals') }}
            </h3>
            <EmptyState
                v-if="!files.length"
                :icon="FileText"
                tone="muted"
                compact
                :title="t('scampause.files.none')"
            />
            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="file in files"
                    :key="file.id"
                    class="flex flex-col gap-2 px-3 py-3 sm:flex-row sm:items-center sm:justify-between"
                    data-test="case-file-item"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ file.original_name }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ enumLabel('file_role', file.role) }} ·
                            {{ fmt.bytes(file.size_bytes) }}
                            <template v-if="file.pages">
                                ·
                                {{
                                    tc('scampause.files.pages', file.pages)
                                }}</template
                            >
                        </p>
                        <p
                            class="mt-0.5 flex items-center gap-1 font-mono text-[0.6875rem] text-muted-foreground"
                        >
                            <Fingerprint class="size-3" aria-hidden="true" />
                            sha256 {{ file.sha256.slice(0, 16) }}…
                            <CopyButton
                                :value="file.sha256"
                                :label="t('scampause.files.copy_hash')"
                            />
                        </p>
                    </div>
                    <span
                        v-if="file.purged"
                        class="text-xs text-muted-foreground"
                        >{{ t('scampause.files.purged') }}</span
                    >
                    <Button
                        v-else-if="file.download_url"
                        variant="outline"
                        size="sm"
                        data-test="download-original"
                        @click="ask(file)"
                    >
                        <Download aria-hidden="true" />
                        {{ t('scampause.files.download') }}
                    </Button>
                </li>
            </ul>
        </section>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="t('scampause.files.download_title')"
            :description="t('scampause.files.download_warning')"
            :confirm-label="t('scampause.files.download_confirm')"
            destructive
            @confirm="download"
        />
    </div>
</template>
