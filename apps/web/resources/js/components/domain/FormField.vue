<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        id: string;
        label: string;
        error?: string | null;
        hint?: string | null;
        optional?: boolean;
        optionalLabel?: string | null;
        class?: string;
    }>(),
    {
        error: null,
        hint: null,
        optional: false,
        optionalLabel: null,
        class: '',
    },
);
</script>

<template>
    <div :class="cn('grid content-start gap-1.5', $props.class)">
        <Label :for="id" class="text-sm font-medium">
            {{ label }}
            <span
                v-if="optional && optionalLabel"
                class="font-normal text-muted-foreground"
                >· {{ optionalLabel }}</span
            >
        </Label>
        <slot
            :describedby="
                error ? `${id}-error` : hint ? `${id}-hint` : undefined
            "
            :invalid="Boolean(error)"
        />
        <p
            v-if="hint && !error"
            :id="`${id}-hint`"
            class="text-xs text-pretty text-muted-foreground"
        >
            {{ hint }}
        </p>
        <p
            v-if="error"
            :id="`${id}-error`"
            role="alert"
            class="flex items-start gap-1.5 text-sm font-medium text-destructive"
            data-test="field-error"
        >
            <CircleAlert
                class="mt-0.5 size-4 shrink-0"
                :stroke-width="2"
                aria-hidden="true"
            />
            {{ error }}
        </p>
    </div>
</template>
