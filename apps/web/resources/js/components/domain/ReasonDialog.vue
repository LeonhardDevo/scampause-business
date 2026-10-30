<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormField from '@/components/domain/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import type { Option } from '@/types/domain';

const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        url: string;
        reasons: Option[];
        confirmLabel: string;
        commentAlwaysRequired?: boolean;
        destructive?: boolean;
        testId?: string;
    }>(),
    {
        commentAlwaysRequired: false,
        destructive: true,
        testId: 'reason-dialog',
    },
);

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ done: [] }>();

const form = useForm({ reason: '', comment: '' });

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
    }
});

const commentRequired = computed(
    () => props.commentAlwaysRequired || form.reason === 'other',
);

const blocked = computed(
    () => !form.reason || (commentRequired.value && !form.comment.trim()),
);

function submit(): void {
    if (blocked.value || form.processing) {
        return;
    }

    form.transform((data) => ({
        reason: data.reason,
        comment: data.comment.trim() || null,
    })).post(props.url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('done');
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" :data-test="testId">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <FormField
                    :id="`${testId}-reason`"
                    :label="t('scampause.decision.reason')"
                    :error="form.errors.reason"
                >
                    <Select v-model="form.reason">
                        <SelectTrigger :id="`${testId}-reason`" class="w-full">
                            <SelectValue
                                :placeholder="t('scampause.common.choose')"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="reason in reasons"
                                :key="reason.value"
                                :value="reason.value"
                                >{{ reason.label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </FormField>
                <FormField
                    :id="`${testId}-comment`"
                    :label="t('scampause.decision.comment')"
                    :error="form.errors.comment"
                    :optional="!commentRequired"
                    :optional-label="t('scampause.common.optional')"
                >
                    <Textarea
                        :id="`${testId}-comment`"
                        v-model="form.comment"
                        rows="3"
                        maxlength="2000"
                    />
                </FormField>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >{{ t('scampause.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :variant="destructive ? 'destructive' : 'default'"
                        :disabled="blocked || form.processing"
                        :aria-busy="form.processing"
                        :data-test="`${testId}-confirm`"
                    >
                        <Spinner v-if="form.processing" />
                        {{ confirmLabel }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
