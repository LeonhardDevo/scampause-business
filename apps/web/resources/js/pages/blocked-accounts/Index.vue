<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Ban, Plus, ShieldCheck, Unlock } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import AccountInputs from '@/components/domain/AccountInputs.vue';
import type { AccountDraft } from '@/components/domain/AccountInputs.vue';
import FormField from '@/components/domain/FormField.vue';
import MaskedAccount from '@/components/domain/MaskedAccount.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { requestPath, sp } from '@/lib/domain/routes';
import { enumLabel } from '@/lib/domain/status';
import { t } from '@/lib/i18n';
import type { BlockedAccountsProps, BlockedItem } from '@/types/domain';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'scampause.nav.blocked', href: sp.blocked.index() },
        ],
    },
});

defineProps<BlockedAccountsProps>();

const fmt = useFormat();

function blankDraft(): AccountDraft {
    return {
        scheme: 'iban',
        account_number: '',
        routing_code: '',
        bic: '',
        account_holder: '',
        bank_name: '',
    };
}

const blockOpen = ref(false);
const draft = ref<AccountDraft>(blankDraft());
const note = ref('');
const blockForm = useForm<Record<string, never>>({});
const blockErrors = computed(
    () => blockForm.errors as Record<string, string | undefined>,
);

function openBlock(): void {
    draft.value = blankDraft();
    note.value = '';
    blockForm.clearErrors();
    blockOpen.value = true;
}

function block(): void {
    if (!draft.value.account_number.trim() || blockForm.processing) {
        return;
    }

    const data = draft.value;

    blockForm
        .transform(() => ({
            scheme: data.scheme,
            account_number: data.account_number,
            routing_code: data.routing_code || null,
            reason: 'manual',
            note: note.value.trim() || null,
        }))
        .post(sp.blocked.store().url, {
            preserveScroll: true,
            onSuccess: () => {
                blockOpen.value = false;
            },
        });
}

const unblockOpen = ref(false);
const target = ref<BlockedItem | null>(null);
const unblockForm = useForm({ reason: '' });

function openUnblock(item: BlockedItem): void {
    target.value = item;
    unblockForm.reset();
    unblockForm.clearErrors();
    unblockOpen.value = true;
}

function unblock(): void {
    if (!target.value || !unblockForm.reason.trim() || unblockForm.processing) {
        return;
    }

    unblockForm.delete(sp.blocked.destroy(target.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            unblockOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="t('scampause.blocked.title')" />

    <div
        class="mx-auto flex w-full max-w-[90rem] flex-col gap-5 px-4 py-6 sm:px-6 lg:py-8"
        data-test="blocked-page"
    >
        <PageHeader
            :icon="Ban"
            :eyebrow="t('scampause.blocked.eyebrow')"
            :title="t('scampause.blocked.title')"
            :description="t('scampause.blocked.description')"
        >
            <template v-if="abilities.manage" #actions>
                <Button
                    variant="destructive"
                    data-test="block-open"
                    @click="openBlock"
                >
                    <Plus aria-hidden="true" />
                    {{ t('scampause.blocked.add') }}
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="!items.data.length"
            class="rounded-xl border bg-card"
            data-test="blocked-empty"
        >
            <EmptyState
                :icon="ShieldCheck"
                tone="muted"
                :title="t('scampause.blocked.empty_title')"
                :description="t('scampause.blocked.empty_body')"
            />
        </div>
        <template v-else>
            <div
                class="hidden overflow-x-auto rounded-xl border bg-card md:block"
            >
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        {{
                            t('scampause.blocked.title')
                        }}
                    </caption>
                    <thead
                        class="border-b text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th scope="col" class="px-3 py-2.5 font-medium">
                                {{ t('scampause.blocked.columns.account') }}
                            </th>
                            <th scope="col" class="px-3 py-2.5 font-medium">
                                {{ t('scampause.blocked.columns.reason') }}
                            </th>
                            <th scope="col" class="px-3 py-2.5 font-medium">
                                {{ t('scampause.blocked.columns.source') }}
                            </th>
                            <th scope="col" class="px-3 py-2.5 font-medium">
                                {{ t('scampause.blocked.columns.by') }}
                            </th>
                            <th scope="col" class="px-3 py-2.5">
                                <span class="sr-only">{{
                                    t('scampause.common.actions')
                                }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="item in items.data"
                            :key="item.id"
                            class="h-11"
                            data-test="blocked-row"
                        >
                            <td class="px-3 py-2">
                                <MaskedAccount :last4="item.last4" />
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ enumLabel('bank_scheme', item.scheme) }}
                                    <template v-if="item.bank_name">
                                        · {{ item.bank_name }}</template
                                    >
                                    <template v-if="item.bank_country">
                                        · {{ item.bank_country }}</template
                                    >
                                </p>
                            </td>
                            <td class="px-3 py-2">
                                {{ enumLabel('block_reason', item.reason) }}
                                <p
                                    v-if="item.note"
                                    class="text-xs text-pretty text-muted-foreground"
                                >
                                    {{ item.note }}
                                </p>
                            </td>
                            <td class="px-3 py-2">
                                <Link
                                    v-if="item.request"
                                    :href="requestPath(item.request.id)"
                                    class="font-mono text-primary hover:underline"
                                    >{{ item.request.reference }}</Link
                                >
                                <span v-else class="text-muted-foreground">{{
                                    t('scampause.blocked.manual')
                                }}</span>
                            </td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ item.created_by.name }} ·
                                {{ fmt.date(item.created_at) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Button
                                    v-if="abilities.manage"
                                    variant="ghost"
                                    size="sm"
                                    data-test="unblock"
                                    @click="openUnblock(item)"
                                >
                                    <Unlock aria-hidden="true" />
                                    {{ t('scampause.blocked.unblock') }}
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ul class="space-y-2 md:hidden">
                <li
                    v-for="item in items.data"
                    :key="item.id"
                    class="rounded-xl border bg-card p-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <MaskedAccount :last4="item.last4" />
                        <span class="text-xs text-muted-foreground">{{
                            fmt.date(item.created_at)
                        }}</span>
                    </div>
                    <p class="mt-1 text-sm">
                        {{ enumLabel('block_reason', item.reason) }}
                    </p>
                    <Button
                        v-if="abilities.manage"
                        variant="ghost"
                        size="sm"
                        class="mt-2"
                        @click="openUnblock(item)"
                    >
                        <Unlock aria-hidden="true" />
                        {{ t('scampause.blocked.unblock') }}
                    </Button>
                </li>
            </ul>
            <LaravelPagination
                :links="items.links"
                :meta="items.meta"
                :count="items.data.length"
            />
        </template>
    </div>

    <Dialog v-model:open="blockOpen">
        <DialogContent class="sm:max-w-2xl" data-test="block-dialog">
            <DialogHeader>
                <DialogTitle>{{ t('scampause.blocked.add') }}</DialogTitle>
                <DialogDescription>{{
                    t('scampause.blocked.add_body')
                }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="block">
                <AccountInputs
                    v-model="draft"
                    id-prefix="block"
                    :errors="blockErrors"
                    :show-holder="false"
                    :show-bank="false"
                />
                <FormField
                    id="block-note"
                    :label="t('scampause.blocked.note')"
                    :error="blockErrors.note"
                    optional
                    :optional-label="t('scampause.common.optional')"
                >
                    <Textarea
                        id="block-note"
                        v-model="note"
                        rows="3"
                        maxlength="1000"
                    />
                </FormField>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="blockOpen = false"
                        >{{ t('scampause.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="
                            !draft.account_number.trim() || blockForm.processing
                        "
                        data-test="block-confirm"
                    >
                        <Spinner v-if="blockForm.processing" />
                        <Ban v-else aria-hidden="true" />
                        {{ t('scampause.blocked.confirm') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="unblockOpen">
        <DialogContent class="sm:max-w-md" data-test="unblock-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    t('scampause.blocked.unblock_title')
                }}</DialogTitle>
                <DialogDescription>{{
                    t('scampause.blocked.unblock_body')
                }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="unblock">
                <FormField
                    id="unblock-reason"
                    :label="t('scampause.decision.reason')"
                    :error="unblockForm.errors.reason"
                >
                    <Textarea
                        id="unblock-reason"
                        v-model="unblockForm.reason"
                        rows="3"
                        maxlength="1000"
                    />
                </FormField>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="unblockOpen = false"
                        >{{ t('scampause.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="
                            !unblockForm.reason.trim() || unblockForm.processing
                        "
                        data-test="unblock-confirm"
                    >
                        <Spinner v-if="unblockForm.processing" />
                        {{ t('scampause.blocked.unblock') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
