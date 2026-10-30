<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Banknote } from '@lucide/vue';
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
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';

const props = defineProps<{ url: string; amount: string | null }>();

const open = defineModel<boolean>('open', { default: false });

function today(): string {
    const now = new Date();

    return new Date(now.getTime() - now.getTimezoneOffset() * 60_000)
        .toISOString()
        .slice(0, 10);
}

const form = useForm({ paid_at: today(), payment_reference: '' });

watch(open, (value) => {
    if (value) {
        form.reset();
        form.paid_at = today();
        form.clearErrors();
    }
});

const blocked = computed(() => !form.paid_at || !form.payment_reference.trim());

function submit(): void {
    if (blocked.value || form.processing) {
        return;
    }

    form.transform((data) => ({
        paid_at: data.paid_at,
        payment_reference: data.payment_reference.trim(),
    })).post(props.url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md" data-test="mark-paid-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    t('scampause.case.mark_paid_title')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        t('scampause.case.mark_paid_body', {
                            amount: amount ?? '—',
                        })
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <FormField
                    id="paid-at"
                    :label="t('scampause.case.paid_at')"
                    :error="form.errors.paid_at"
                >
                    <Input
                        id="paid-at"
                        v-model="form.paid_at"
                        type="date"
                        :max="today()"
                    />
                </FormField>
                <FormField
                    id="paid-reference"
                    :label="t('scampause.case.payment_reference')"
                    :error="form.errors.payment_reference"
                    :hint="t('scampause.case.payment_reference_hint')"
                >
                    <Input
                        id="paid-reference"
                        v-model="form.payment_reference"
                        maxlength="120"
                        class="font-mono"
                        data-test="payment-reference"
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
                        :disabled="blocked || form.processing"
                        :aria-busy="form.processing"
                        data-test="mark-paid-confirm"
                    >
                        <Spinner v-if="form.processing" />
                        <Banknote v-else aria-hidden="true" />
                        {{ t('scampause.actions.mark_paid') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
