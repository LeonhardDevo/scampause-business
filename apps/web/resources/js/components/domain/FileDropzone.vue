<script setup lang="ts">
import { FileText, FileUp, Image as ImageIcon, Mail, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import {
    acceptAttribute,
    fileKind,
    validateIntakeFiles,
} from '@/lib/domain/intake';
import type { IntakeIssue } from '@/lib/domain/intake';
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        accept: string[];
        maxFiles: number;
        maxSizeMb: number;
        disabled?: boolean;
        progress?: number | null;
    }>(),
    { disabled: false, progress: null },
);

const files = defineModel<File[]>({ default: () => [] });

const fmt = useFormat();
const input = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
const issues = ref<IntakeIssue[]>([]);

const acceptValue = computed(() => acceptAttribute(props.accept));

function add(list: FileList | File[] | null): void {
    if (!list || props.disabled) {
        return;
    }

    const result = validateIntakeFiles(
        [...files.value, ...Array.from(list)],
        props.accept,
        props.maxFiles,
        props.maxSizeMb,
    );
    files.value = result.accepted;
    issues.value = result.issues;
}

function remove(index: number): void {
    files.value = files.value.filter((_, i) => i !== index);
    issues.value = [];
}

function onDrop(event: DragEvent): void {
    dragging.value = false;
    add(event.dataTransfer?.files ?? null);
}

function onChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    add(target.files);
    target.value = '';
}

const ICONS = { pdf: FileText, image: ImageIcon, eml: Mail, other: FileText };

function issueText(issue: IntakeIssue): string {
    return t(`scampause.intake.issues.${issue.code}`, {
        name: issue.name,
        max: issue.code === 'too_many' ? props.maxFiles : props.maxSizeMb,
    });
}
</script>

<template>
    <div class="space-y-3">
        <div
            :class="[
                'relative flex min-h-[220px] flex-col items-center justify-center gap-3 overflow-hidden rounded-xl border-2 border-dashed px-6 py-8 text-center transition-colors',
                dragging
                    ? 'border-primary bg-accent'
                    : 'border-input bg-card hover:border-ring/50',
                disabled ? 'pointer-events-none opacity-60' : '',
            ]"
            data-test="dropzone"
            @dragenter.prevent="dragging = true"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <div
                class="pointer-events-none absolute inset-0 bg-grid-paper opacity-60"
                aria-hidden="true"
            />
            <span
                class="relative inline-flex size-12 items-center justify-center rounded-lg border bg-card text-primary"
                aria-hidden="true"
            >
                <FileUp class="size-6" :stroke-width="1.5" />
            </span>
            <div class="relative space-y-1">
                <p class="font-medium">
                    {{ t('scampause.intake.drop_title') }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        t('scampause.intake.drop_hint', {
                            max: maxFiles,
                            size: maxSizeMb,
                        })
                    }}
                </p>
            </div>
            <Button
                type="button"
                variant="outline"
                class="relative"
                :disabled="disabled"
                @click="input?.click()"
            >
                {{ t('scampause.intake.browse') }}
            </Button>
            <input
                ref="input"
                type="file"
                multiple
                class="sr-only"
                :accept="acceptValue"
                :aria-label="t('scampause.intake.browse')"
                data-test="file-input"
                @change="onChange"
            />
        </div>

        <ul v-if="issues.length" class="space-y-1" role="alert">
            <li
                v-for="(issue, index) in issues"
                :key="index"
                class="text-sm text-destructive"
            >
                {{ issueText(issue) }}
            </li>
        </ul>

        <ul v-if="files.length" class="divide-y rounded-lg border bg-card">
            <li
                v-for="(file, index) in files"
                :key="`${file.name}-${index}`"
                class="flex items-center gap-3 px-3 py-2.5"
                data-test="selected-file"
            >
                <component
                    :is="ICONS[fileKind(file)]"
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ file.name }}</p>
                    <p class="text-xs text-muted-foreground tabular-nums">
                        {{ fmt.bytes(file.size) }}
                    </p>
                    <div
                        v-if="progress !== null"
                        class="mt-1 h-1 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-valuenow="progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="t('scampause.intake.uploading')"
                    >
                        <div
                            class="h-full bg-primary transition-[width]"
                            :style="{ width: `${progress}%` }"
                        />
                    </div>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :disabled="disabled"
                    :aria-label="
                        t('scampause.intake.remove', { name: file.name })
                    "
                    @click="remove(index)"
                >
                    <X aria-hidden="true" />
                </Button>
            </li>
        </ul>
    </div>
</template>
