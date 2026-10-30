<script setup lang="ts">
import { Link, useForm, useHttp } from '@inertiajs/vue3';
import { Archive, Ban, Eye, Landmark, PhoneCall, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import EmptyState from '@/components/common/EmptyState.vue';
import AccountInputs from '@/components/domain/AccountInputs.vue';
import type { AccountDraft } from '@/components/domain/AccountInputs.vue';
import ExhibitCard from '@/components/domain/ExhibitCard.vue';
import MaskedAccount from '@/components/domain/MaskedAccount.vue';
import ReasonDialog from '@/components/domain/ReasonDialog.vue';
import VerificationSheet from '@/components/domain/VerificationSheet.vue';
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
import { useFormat } from '@/composables/useFormat';
import { groupByFour } from '@/lib/domain/bank-validators';
import { sp } from '@/lib/domain/routes';
import { enumLabel } from '@/lib/domain/status';
import { describeHttpError } from '@/lib/http-errors';
import { t } from '@/lib/i18n';
import type {
    BankAccountStatus,
    BlockedAttempt,
    CallbackContact,
    RetiredReason,
    RevealedBankDetails,
    VendorAccountDto,
    VerificationMethod,
} from '@/types/domain';

const props = defineProps<{
    vendorId: number;
    accounts: VendorAccountDto[];
    blockedAttempts: BlockedAttempt[];
    contacts: CallbackContact[];
    currencies?: string[];
    abilities: { manage: boolean; verify_bank: boolean; reveal: boolean };
    defaultScheme?: AccountDraft['scheme'];
}>();

const fmt = useFormat();

type Entry =
    | { type: 'account'; at: string; account: VendorAccountDto }
    | { type: 'blocked'; at: string; attempt: BlockedAttempt };

const entries = computed<Entry[]>(() =>
    [
        ...props.accounts.map((account) => ({
            type: 'account' as const,
            at: account.verified_at ?? account.created_at,
            account,
        })),
        ...props.blockedAttempts.map((attempt) => ({
            type: 'blocked' as const,
            at: attempt.created_at,
            attempt,
        })),
    ].sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime()),
);

const NODE: Record<BankAccountStatus | 'blocked', string> = {
    verified: 'border-success bg-success',
    pending_verification: 'border-warning bg-warning',
    retired: 'border-input bg-muted',
    rejected: 'border-destructive bg-destructive',
    blocked: 'border-destructive bg-destructive',
};

const methods = computed(() =>
    (
        [
            'callback_known_number',
            'in_person',
            'video_call_known_contact',
            'signed_letter_and_callback',
            'vendor_portal',
        ] as VerificationMethod[]
    ).map((value) => ({
        value,
        label: enumLabel('verification_method', value),
        allowed: value !== 'vendor_portal',
    })),
);

const verifyOpen = ref(false);
const verifying = ref<VendorAccountDto | null>(null);

function openVerify(account: VendorAccountDto): void {
    verifying.value = account;
    verifyOpen.value = true;
}

const replaceable = computed(() =>
    props.accounts
        .filter(
            (account) =>
                account.status === 'verified' &&
                account.id !== verifying.value?.id,
        )
        .map((account) => ({ id: account.id, label: account.masked })),
);

const retireOpen = ref(false);
const retiring = ref<VendorAccountDto | null>(null);
const retireReasons = computed(() =>
    (
        ['replaced', 'closed', 'fraud', 'entered_in_error'] as RetiredReason[]
    ).map((value) => ({
        value,
        label: enumLabel('retired_reason', value),
    })),
);

function openRetire(account: VendorAccountDto): void {
    retiring.value = account;
    retireOpen.value = true;
}

const http = useHttp<Record<string, never>, RevealedBankDetails>({});
const revealed = ref<RevealedBankDetails | null>(null);
const revealOpen = ref(false);

async function reveal(account: VendorAccountDto): Promise<void> {
    try {
        const result = await http.post(
            sp.vendors.accounts.reveal(props.vendorId, account.id).url,
        );

        if (result) {
            revealed.value = result;
            revealOpen.value = true;
        }
    } catch (error) {
        const info = describeHttpError(error);
        toast.error(
            info.status === 429
                ? t('scampause.bank_diff.reveal_throttled')
                : (info.message ?? t('scampause.bank_diff.reveal_failed')),
        );
    }
}

const revealedAccount = computed(
    () => revealed.value?.incoming ?? revealed.value?.previous ?? null,
);

const addOpen = ref(false);

function blankDraft(): AccountDraft {
    return {
        scheme: props.defaultScheme ?? 'iban',
        account_number: '',
        routing_code: '',
        bic: '',
        account_holder: '',
        bank_name: '',
        bank_country: '',
        currency: '',
    };
}

const draft = ref<AccountDraft>(blankDraft());
const addForm = useForm<Record<string, never>>({});
const addErrors = computed(
    () => addForm.errors as Record<string, string | undefined>,
);

function openAdd(): void {
    draft.value = blankDraft();
    addForm.clearErrors();
    addOpen.value = true;
}

function saveAccount(): void {
    const data = draft.value;

    if (!data.account_number.trim() || addForm.processing) {
        return;
    }

    addForm
        .transform(() => ({
            scheme: data.scheme,
            account_number: data.account_number,
            routing_code: data.routing_code || null,
            bic: data.bic || null,
            account_holder: data.account_holder || null,
            bank_name: data.bank_name || null,
            bank_country: data.bank_country
                ? data.bank_country.toUpperCase()
                : null,
            currency: data.currency || null,
        }))
        .post(sp.vendors.accounts.store(props.vendorId).url, {
            preserveScroll: true,
            onSuccess: () => {
                addOpen.value = false;
            },
        });
}
</script>

<template>
    <ExhibitCard
        :eyebrow="t('scampause.vendor.exhibits.bank')"
        :title="t('scampause.vendor.history_title')"
        :description="t('scampause.vendor.history_body')"
        data-test="bank-history"
    >
        <template v-if="abilities.manage" #actions>
            <Button
                size="sm"
                variant="outline"
                data-test="add-account"
                @click="openAdd"
            >
                <Plus aria-hidden="true" />
                {{ t('scampause.vendor.add_account') }}
            </Button>
        </template>

        <EmptyState
            v-if="!entries.length"
            :icon="Landmark"
            tone="muted"
            compact
            :title="t('scampause.vendor.no_accounts')"
            :description="t('scampause.vendor.no_accounts_body')"
        />
        <ol v-else class="relative space-y-5 border-l pl-6">
            <li
                v-for="(entry, index) in entries"
                :key="
                    entry.type === 'account'
                        ? `a-${entry.account.id}`
                        : `b-${index}`
                "
                class="relative"
                :data-test="
                    entry.type === 'account'
                        ? 'history-account'
                        : 'history-blocked'
                "
                :data-status="
                    entry.type === 'account' ? entry.account.status : 'blocked'
                "
            >
                <span
                    :class="[
                        'absolute top-1 -left-[1.95rem] size-3.5 rounded-full border-2',
                        NODE[
                            entry.type === 'account'
                                ? entry.account.status
                                : 'blocked'
                        ],
                    ]"
                    aria-hidden="true"
                />
                <template v-if="entry.type === 'account'">
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="min-w-0 space-y-1">
                            <p class="flex flex-wrap items-center gap-2">
                                <MaskedAccount
                                    :masked="entry.account.masked"
                                    :last4="entry.account.last4"
                                />
                                <span class="text-sm font-medium">{{
                                    enumLabel(
                                        'bank_account_status',
                                        entry.account.status,
                                    )
                                }}</span>
                                <span
                                    v-if="entry.account.currency"
                                    class="font-mono text-xs text-muted-foreground"
                                    >{{ entry.account.currency }}</span
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    enumLabel(
                                        'bank_scheme',
                                        entry.account.scheme,
                                    )
                                }}
                                <template v-if="entry.account.bank_name">
                                    · {{ entry.account.bank_name }}</template
                                >
                                <template v-if="entry.account.bank_country">
                                    · {{ entry.account.bank_country }}</template
                                >
                                <template v-if="entry.account.holder_masked">
                                    ·
                                    {{ entry.account.holder_masked }}</template
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t('scampause.vendor.added_by', {
                                        name: entry.account.created_by.name,
                                        date: fmt.date(
                                            entry.account.created_at,
                                        ),
                                    })
                                }}
                                <template v-if="entry.account.verified_at">
                                    ·
                                    {{
                                        t('scampause.vendor.verified_line', {
                                            name:
                                                entry.account.verified_by
                                                    ?.name ?? '—',
                                            date: fmt.date(
                                                entry.account.verified_at,
                                            ),
                                            method: enumLabel(
                                                'verification_method',
                                                entry.account
                                                    .verification_method,
                                            ),
                                        })
                                    }}
                                </template>
                            </p>
                            <p
                                v-if="
                                    entry.account.valid_to ||
                                    entry.account.retired_reason
                                "
                                class="text-xs text-muted-foreground"
                            >
                                <template v-if="entry.account.valid_from">{{
                                    fmt.date(entry.account.valid_from)
                                }}</template>
                                <template v-if="entry.account.valid_to">
                                    —
                                    {{
                                        fmt.date(entry.account.valid_to)
                                    }}</template
                                >
                                <template v-if="entry.account.retired_reason">
                                    ·
                                    {{
                                        enumLabel(
                                            'retired_reason',
                                            entry.account.retired_reason,
                                        )
                                    }}</template
                                >
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-1.5">
                            <Button
                                v-if="abilities.reveal"
                                variant="ghost"
                                size="sm"
                                :disabled="http.processing"
                                @click="reveal(entry.account)"
                            >
                                <Eye aria-hidden="true" />
                                {{ t('scampause.bank_diff.reveal') }}
                            </Button>
                            <Button
                                v-if="
                                    abilities.verify_bank &&
                                    entry.account.status ===
                                        'pending_verification'
                                "
                                size="sm"
                                data-test="verify-account"
                                @click="openVerify(entry.account)"
                            >
                                <PhoneCall aria-hidden="true" />
                                {{ t('scampause.vendor.verify_account') }}
                            </Button>
                            <Button
                                v-if="
                                    abilities.verify_bank &&
                                    entry.account.status === 'verified'
                                "
                                variant="outline"
                                size="sm"
                                @click="openRetire(entry.account)"
                            >
                                <Archive aria-hidden="true" />
                                {{ t('scampause.vendor.retire') }}
                            </Button>
                        </div>
                    </div>
                </template>
                <template v-else>
                    <p
                        class="flex flex-wrap items-center gap-2 text-sm font-medium text-destructive"
                    >
                        <Ban class="size-4" aria-hidden="true" />
                        {{ t('scampause.vendor.blocked_attempt') }}
                        <MaskedAccount :last4="entry.attempt.last4" />
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ fmt.dateTime(entry.attempt.created_at) }} ·
                        {{ enumLabel('block_reason', entry.attempt.reason) }}
                        <template v-if="entry.attempt.bank_country">
                            · {{ entry.attempt.bank_country }}</template
                        >
                        <template v-if="entry.attempt.request_reference">
                            ·
                            <Link
                                :href="
                                    sp.requests.index({
                                        q: entry.attempt.request_reference,
                                    })
                                "
                                class="font-mono text-primary hover:underline"
                                >{{ entry.attempt.request_reference }}</Link
                            >
                        </template>
                    </p>
                </template>
            </li>
        </ol>

        <VerificationSheet
            v-if="verifying"
            v-model:open="verifyOpen"
            :title="t('scampause.vendor.verify_account_title')"
            :action="sp.vendors.accounts.verify(vendorId, verifying.id).url"
            :methods="methods"
            :contacts="contacts"
            :checklist="[
                'account_last4',
                'account_holder',
                'change_initiated_by_vendor',
            ]"
            :replaceable="replaceable"
        />
        <ReasonDialog
            v-if="retiring"
            v-model:open="retireOpen"
            :title="t('scampause.vendor.retire_title')"
            :description="t('scampause.vendor.retire_body')"
            :url="sp.vendors.accounts.retire(vendorId, retiring.id).url"
            :reasons="retireReasons"
            :confirm-label="t('scampause.vendor.retire')"
            test-id="retire-dialog"
        />

        <Dialog v-model:open="revealOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        t('scampause.bank_diff.reveal_title')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t('scampause.bank_diff.reveal_logged')
                    }}</DialogDescription>
                </DialogHeader>
                <dl v-if="revealedAccount" class="space-y-2 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ t('scampause.bank_diff.fields.account') }}
                        </dt>
                        <dd class="font-mono break-all">
                            {{ groupByFour(revealedAccount.account) }}
                        </dd>
                    </div>
                    <div v-if="revealedAccount.routing">
                        <dt class="text-xs text-muted-foreground">
                            {{ t('scampause.bank_diff.fields.routing') }}
                        </dt>
                        <dd class="font-mono">{{ revealedAccount.routing }}</dd>
                    </div>
                    <div v-if="revealedAccount.bic">
                        <dt class="text-xs text-muted-foreground">
                            {{ t('scampause.bank_diff.fields.bic') }}
                        </dt>
                        <dd class="font-mono">{{ revealedAccount.bic }}</dd>
                    </div>
                    <div v-if="revealedAccount.holder">
                        <dt class="text-xs text-muted-foreground">
                            {{ t('scampause.bank_diff.fields.holder') }}
                        </dt>
                        <dd>{{ revealedAccount.holder }}</dd>
                    </div>
                </dl>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="addOpen">
            <DialogContent class="sm:max-w-2xl" data-test="account-dialog">
                <DialogHeader>
                    <DialogTitle>{{
                        t('scampause.vendor.add_account')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t('scampause.vendor.add_account_body')
                    }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="saveAccount">
                    <AccountInputs
                        v-model="draft"
                        id-prefix="vendor-account"
                        :errors="addErrors"
                        show-country
                        :currencies="currencies ?? []"
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="addOpen = false"
                            >{{ t('scampause.common.cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="
                                !draft.account_number.trim() ||
                                addForm.processing
                            "
                            data-test="account-save"
                        >
                            <Spinner v-if="addForm.processing" />
                            {{ t('scampause.common.save') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </ExhibitCard>
</template>
