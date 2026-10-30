<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    EllipsisVertical,
    PhoneCall,
    Plus,
    ShieldCheck,
    ShieldQuestion,
    Trash2,
    UserRoundCheck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import CopyButton from '@/components/common/CopyButton.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ExhibitCard from '@/components/domain/ExhibitCard.vue';
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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { sp } from '@/lib/domain/routes';
import { enumLabel } from '@/lib/domain/status';
import { t } from '@/lib/i18n';
import type { ContactSource, VendorContactDto } from '@/types/domain';

const props = defineProps<{
    vendorId: number;
    contacts: VendorContactDto[];
    canManage: boolean;
}>();

const page = usePage();
const isOwner = computed(() => page.props.workspace?.role === 'owner');

const SOURCES: ContactSource[] = [
    'contract',
    'website_verified',
    'in_person',
    'onboarding_form',
    'invoice_email',
];

const dialogOpen = ref(false);
const editing = ref<VendorContactDto | null>(null);

const form = useForm({
    name: '',
    role_title: '',
    email: '',
    phone: '',
    is_callback: true,
    source: 'contract' as ContactSource,
    source_ref: '',
});

function openCreate(): void {
    editing.value = null;
    form.defaults({
        name: '',
        role_title: '',
        email: '',
        phone: '',
        is_callback: true,
        source: 'contract',
        source_ref: '',
    });
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(contact: VendorContactDto): void {
    editing.value = contact;
    form.defaults({
        name: contact.name,
        role_title: contact.role_title ?? '',
        email: contact.email ?? '',
        phone: contact.phone_display ?? '',
        is_callback: contact.is_callback,
        source: contact.source,
        source_ref: contact.source_ref ?? '',
    });
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

const callbackFromMessage = computed(
    () => form.is_callback && form.source === 'invoice_email',
);

const blocked = computed(
    () =>
        !form.name.trim() ||
        callbackFromMessage.value ||
        (form.is_callback && (!form.phone.trim() || !form.source_ref.trim())),
);

function save(): void {
    if (blocked.value || form.processing) {
        return;
    }

    const request = form.transform((data) => ({
        name: data.name.trim(),
        role_title: data.role_title || null,
        email: data.email || null,
        phone: data.phone || null,
        is_callback: data.is_callback,
        source: data.source,
        source_ref: data.source_ref || null,
    }));
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editing.value) {
        request.patch(
            sp.vendors.contacts.update(props.vendorId, editing.value.id).url,
            options,
        );
    } else {
        request.post(sp.vendors.contacts.store(props.vendorId).url, options);
    }
}

const removing = ref<VendorContactDto | null>(null);
const removeOpen = ref(false);
const busy = ref<number | null>(null);

function askRemove(contact: VendorContactDto): void {
    removing.value = contact;
    removeOpen.value = true;
}

function remove(): void {
    if (!removing.value) {
        return;
    }

    router.delete(
        sp.vendors.contacts.destroy(props.vendorId, removing.value.id).url,
        {
            preserveScroll: true,
            onFinish: () => {
                removeOpen.value = false;
            },
        },
    );
}

function verify(contact: VendorContactDto): void {
    busy.value = contact.id;
    router.post(
        sp.vendors.contacts.verify(props.vendorId, contact.id).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

function privacy(contact: VendorContactDto, action: 'export' | 'redact'): void {
    router.post(
        sp.vendors.dataSubject(props.vendorId).url,
        { action, subject: { type: 'contact', id: contact.id } },
        { preserveScroll: true },
    );
}
</script>

<template>
    <ExhibitCard
        :eyebrow="t('scampause.vendor.exhibits.callback')"
        :title="t('scampause.vendor.contacts_title')"
        :description="t('scampause.vendor.contacts_body')"
        data-test="vendor-contacts"
    >
        <template v-if="canManage" #actions>
            <Button
                size="sm"
                variant="outline"
                data-test="add-contact"
                @click="openCreate"
            >
                <Plus aria-hidden="true" />
                {{ t('scampause.vendor.add_contact') }}
            </Button>
        </template>

        <EmptyState
            v-if="!contacts.length"
            :icon="PhoneCall"
            tone="warning"
            compact
            :title="t('scampause.vendor.no_contacts')"
            :description="t('scampause.vendor.no_contacts_body')"
        />
        <ul v-else class="divide-y rounded-lg border">
            <li
                v-for="contact in contacts"
                :key="contact.id"
                class="flex flex-col gap-2 px-3 py-3 sm:flex-row sm:items-start sm:justify-between"
                data-test="vendor-contact"
                :data-status="contact.status"
            >
                <div class="min-w-0 space-y-0.5">
                    <p class="flex flex-wrap items-center gap-2 font-medium">
                        {{ contact.name }}
                        <span
                            v-if="contact.role_title"
                            class="text-sm font-normal text-muted-foreground"
                            >{{ contact.role_title }}</span
                        >
                        <span
                            v-if="contact.is_callback"
                            :class="[
                                'inline-flex h-5 items-center gap-1 rounded-sm border px-1.5 text-[0.6875rem] font-medium',
                                contact.status === 'verified'
                                    ? 'border-success/30 bg-success-soft text-success-soft-foreground'
                                    : 'border-warning/30 bg-warning-soft text-warning-soft-foreground',
                            ]"
                        >
                            <ShieldCheck
                                v-if="contact.status === 'verified'"
                                class="size-3"
                                aria-hidden="true"
                            />
                            <ShieldQuestion
                                v-else
                                class="size-3"
                                aria-hidden="true"
                            />
                            {{
                                contact.status
                                    ? enumLabel(
                                          'contact_status',
                                          contact.status,
                                      )
                                    : t('scampause.vendor.callback')
                            }}
                        </span>
                    </p>
                    <p
                        v-if="contact.phone_display"
                        class="flex items-center gap-1 font-mono text-sm tabular-nums"
                    >
                        {{ contact.phone_display }}
                        <CopyButton
                            :value="contact.phone_display"
                            :label="t('scampause.scc.copy_number')"
                        />
                    </p>
                    <p
                        v-if="contact.email"
                        class="text-sm text-muted-foreground"
                    >
                        {{ contact.email }}
                    </p>
                    <p class="text-xs text-pretty text-muted-foreground">
                        {{ enumLabel('contact_source', contact.source) }}
                        <template v-if="contact.source_ref">
                            · {{ contact.source_ref }}</template
                        >
                    </p>
                    <p
                        v-if="
                            contact.is_callback &&
                            contact.status === 'pending_verification'
                        "
                        class="text-xs text-hazard-ink"
                    >
                        {{ t('scampause.vendor.pending_contact_hint') }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    <Button
                        v-if="contact.can_verify"
                        size="sm"
                        variant="secondary"
                        :disabled="busy === contact.id"
                        data-test="verify-contact"
                        @click="verify(contact)"
                    >
                        <Spinner v-if="busy === contact.id" />
                        <UserRoundCheck v-else aria-hidden="true" />
                        {{ t('scampause.vendor.verify_contact') }}
                    </Button>
                    <DropdownMenu v-if="canManage || isOwner">
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="t('scampause.common.more')"
                            >
                                <EllipsisVertical aria-hidden="true" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                v-if="canManage"
                                @select="openEdit(contact)"
                            >
                                {{ t('scampause.common.edit') }}
                            </DropdownMenuItem>
                            <template v-if="isOwner">
                                <DropdownMenuItem
                                    @select="privacy(contact, 'export')"
                                >
                                    {{ t('scampause.vendor.privacy_export') }}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    @select="privacy(contact, 'redact')"
                                >
                                    {{ t('scampause.vendor.privacy_redact') }}
                                </DropdownMenuItem>
                            </template>
                            <template v-if="canManage">
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    class="text-destructive"
                                    @select="askRemove(contact)"
                                >
                                    <Trash2 aria-hidden="true" />
                                    {{ t('scampause.common.delete') }}
                                </DropdownMenuItem>
                            </template>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </li>
        </ul>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-lg" data-test="contact-dialog">
                <DialogHeader>
                    <DialogTitle>{{
                        editing
                            ? t('scampause.vendor.edit_contact')
                            : t('scampause.vendor.add_contact')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t('scampause.vendor.contact_dialog_body')
                    }}</DialogDescription>
                </DialogHeader>
                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
                    <FormField
                        id="contact-name"
                        :label="t('scampause.vendor.contact_name')"
                        :error="form.errors.name"
                    >
                        <Input
                            id="contact-name"
                            v-model="form.name"
                            maxlength="160"
                        />
                    </FormField>
                    <FormField
                        id="contact-role"
                        :label="t('scampause.vendor.contact_role')"
                        :error="form.errors.role_title"
                        optional
                        :optional-label="t('scampause.common.optional')"
                    >
                        <Input
                            id="contact-role"
                            v-model="form.role_title"
                            maxlength="120"
                        />
                    </FormField>
                    <FormField
                        id="contact-phone"
                        :label="t('scampause.vendor.contact_phone')"
                        :error="form.errors.phone"
                        :optional="!form.is_callback"
                        :optional-label="t('scampause.common.optional')"
                    >
                        <Input
                            id="contact-phone"
                            v-model="form.phone"
                            type="tel"
                            class="font-mono"
                            maxlength="32"
                        />
                    </FormField>
                    <FormField
                        id="contact-email"
                        :label="t('scampause.vendor.contact_email')"
                        :error="form.errors.email"
                        optional
                        :optional-label="t('scampause.common.optional')"
                    >
                        <Input
                            id="contact-email"
                            v-model="form.email"
                            type="email"
                        />
                    </FormField>
                    <Label
                        for="contact-callback"
                        class="flex cursor-pointer items-center gap-3 rounded-md border p-3 font-normal sm:col-span-2"
                    >
                        <Switch
                            id="contact-callback"
                            v-model="form.is_callback"
                        />
                        <span>
                            <span class="block font-medium">{{
                                t('scampause.vendor.is_callback')
                            }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                t('scampause.vendor.is_callback_hint')
                            }}</span>
                        </span>
                    </Label>
                    <FormField
                        id="contact-source"
                        :label="t('scampause.vendor.contact_source')"
                        :error="form.errors.source"
                    >
                        <Select v-model="form.source">
                            <SelectTrigger id="contact-source" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="source in SOURCES"
                                    :key="source"
                                    :value="source"
                                    >{{
                                        enumLabel('contact_source', source)
                                    }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        id="contact-source-ref"
                        :label="t('scampause.vendor.contact_source_ref')"
                        :error="form.errors.source_ref"
                        :hint="t('scampause.vendor.contact_source_ref_hint')"
                        :optional="!form.is_callback"
                        :optional-label="t('scampause.common.optional')"
                    >
                        <Input
                            id="contact-source-ref"
                            v-model="form.source_ref"
                            maxlength="255"
                        />
                    </FormField>
                    <p
                        v-if="callbackFromMessage"
                        class="text-sm text-destructive sm:col-span-2"
                        role="alert"
                    >
                        {{ t('scampause.vendor.callback_from_email') }}
                    </p>
                    <DialogFooter class="sm:col-span-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >{{ t('scampause.common.cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="blocked || form.processing"
                            data-test="contact-save"
                        >
                            <Spinner v-if="form.processing" />
                            {{ t('scampause.common.save') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            v-model:open="removeOpen"
            :title="t('scampause.vendor.remove_contact_title')"
            :description="t('scampause.vendor.remove_contact_body')"
            :confirm-label="t('scampause.common.delete')"
            destructive
            @confirm="remove"
        />
    </ExhibitCard>
</template>
