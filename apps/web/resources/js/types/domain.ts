import type { Paginated } from '@/types/models';

export type RiskLevel = 'low' | 'medium' | 'high' | 'critical';
export type PaymentRequestStatus =
    | 'received'
    | 'processing'
    | 'review'
    | 'awaiting_verification'
    | 'awaiting_approval'
    | 'approved'
    | 'paid'
    | 'rejected'
    | 'expired'
    | 'cancelled';
export type PaymentRequestKind =
    | 'invoice'
    | 'bank_details_change'
    | 'urgent_wire'
    | 'payroll_change'
    | 'qr_payment'
    | 'other';
export type PaymentRequestSource = 'upload' | 'eml' | 'manual';
export type BankScheme =
    | 'iban'
    | 'us_ach'
    | 'ru_account'
    | 'uk_sort_code'
    | 'other';
export type VendorKind =
    | 'supplier'
    | 'contractor'
    | 'employee'
    | 'government'
    | 'other';
export type VendorStatus = 'active' | 'on_hold' | 'archived';
export type ContactSource =
    | 'contract'
    | 'website_verified'
    | 'in_person'
    | 'onboarding_form'
    | 'invoice_email';
export type ContactStatus = 'pending_verification' | 'verified' | 'retired';
export type DomainSource = 'contract' | 'manual' | 'verified_correspondence';
export type BankAccountStatus =
    | 'pending_verification'
    | 'verified'
    | 'rejected'
    | 'retired';
export type RetiredReason =
    | 'replaced'
    | 'closed'
    | 'fraud'
    | 'entered_in_error';
export type ApprovalStatus =
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'expired'
    | 'revoked'
    | 'reassigned';
export type VerificationMethod =
    | 'callback_known_number'
    | 'in_person'
    | 'video_call_known_contact'
    | 'signed_letter_and_callback'
    | 'vendor_portal';
export type VerificationOutcome =
    | 'confirmed'
    | 'denied'
    | 'unreachable'
    | 'inconclusive';
export type DecisionReason =
    | 'fraud_confirmed'
    | 'details_mismatch'
    | 'not_ordered'
    | 'duplicate'
    | 'policy_violation'
    | 'other';
export type CancelReason =
    | 'withdrawn'
    | 'duplicate_upload'
    | 'entered_in_error'
    | 'other';
export type SignalCategory =
    | 'bank'
    | 'sender'
    | 'content'
    | 'amount'
    | 'workflow'
    | 'qr';
export type SignalSeverity = 'info' | 'low' | 'medium' | 'high' | 'critical';
export type PipelineState =
    | 'pending'
    | 'running'
    | 'done'
    | 'failed'
    | 'skipped';
export type PipelineStage = 'extract' | 'domain' | 'bank' | 'score';
export type Role =
    | 'owner'
    | 'admin'
    | 'approver'
    | 'bookkeeper'
    | 'member'
    | 'auditor';
export type Channel = 'web' | 'telegram' | 'system' | 'worker';
export type ExportStatus =
    | 'queued'
    | 'generating'
    | 'ready'
    | 'failed'
    | 'expired';
export type ExportFormat = 'pdf' | 'csv' | 'json';
export type ReportPreset = '30d' | '90d' | 'ytd' | '12m' | 'custom';

export type Option<T extends string = string> = { value: T; label: string };
export type Usage = { used: number; limit: number | null };

export type MoneyDto = { minor: number; currency: string; formatted: string };
export type UserRef = { id: number; name: string; avatar: string | null };
export type VendorRef = {
    id: number;
    name: string;
    kind: VendorKind;
    status: VendorStatus;
};
export type MaskedAccount = {
    scheme: BankScheme;
    last4: string;
    masked: string;
    bank_name: string | null;
    bank_country: string | null;
    bic: string | null;
    holder_masked: string | null;
};
export type EvidenceValue =
    | string
    | number
    | boolean
    | null
    | string[]
    | Record<string, unknown>;
export type RiskSignal = {
    code: string;
    category: SignalCategory;
    severity: SignalSeverity;
    points: number;
    title: string;
    explanation: string;
    advice: string;
    evidence: Record<string, EvidenceValue> | EvidenceValue[];
};
export type PipelineStageState = {
    state: PipelineState;
    at: string | null;
    error: string | null;
};
export type PipelineStatus = {
    status: PaymentRequestStatus;
    revision: number;
    stages: Record<PipelineStage, PipelineStageState>;
    risk: { score: number; level: RiskLevel } | null;
};
export type PaymentRequestRow = {
    id: number;
    reference: string;
    title: string;
    kind: PaymentRequestKind;
    source: PaymentRequestSource;
    status: PaymentRequestStatus;
    vendor: VendorRef | null;
    amount: MoneyDto | null;
    risk: { score: number; level: RiskLevel; top_signal: string | null } | null;
    verification_required: boolean;
    due_date: string | null;
    expires_at: string | null;
    requested_by: UserRef;
    created_at: string;
    my_pending_approval_id: number | null;
};
export type ExtractionSource =
    | 'text'
    | 'ocr'
    | 'qr'
    | 'eml_header'
    | 'llm_assist'
    | 'manual';
export type ExtractedField<T> = {
    value: T;
    confidence: number;
    source: string;
};
export type BankDiffField =
    | 'scheme'
    | 'account'
    | 'routing'
    | 'bic'
    | 'bank_name'
    | 'bank_country'
    | 'holder'
    | 'currency';
export type BankDiffOutcome =
    | 'match_verified'
    | 'match_pending'
    | 'changed'
    | 'first_use'
    | 'unmatched_vendor'
    | 'no_account_extracted'
    | 'blocklisted'
    | 'other_vendor_account';
export type PreviousAccount = MaskedAccount & {
    verified_at: string | null;
    verified_by: UserRef | null;
    valid_from: string | null;
};
export type BankDiffRowDto = {
    field: BankDiffField;
    previous: string | null;
    incoming: string | null;
    changed: boolean;
};
export type BankDiff = {
    outcome: BankDiffOutcome;
    previous: PreviousAccount | null;
    incoming: MaskedAccount | null;
    rows: BankDiffRowDto[];
    holder_similarity: number | null;
    account_edit_distance: number | null;
};
export type DomainRole = 'from' | 'reply_to' | 'return_path' | 'link';
export type SenderDomain = {
    domain: string;
    unicode: string | null;
    role: DomainRole;
    known_for_vendor: boolean;
    lookalike_of: string | null;
    technique: string | null;
    registered_at: string | null;
    age_days: number | null;
    dmarc_policy: string | null;
    free_mail: boolean;
    checked_at?: string | null;
};
export type SenderPanelDto = {
    from_name: string | null;
    from_email: string | null;
    reply_to: string | null;
    return_path_domain: string | null;
    auth: { spf: string | null; dkim: string | null; dmarc: string | null };
    domains: SenderDomain[];
    phones_in_message: Array<{ last4: string; matches_master: boolean }>;
    notes?: string[];
};
export type ApprovalDto = {
    id: number;
    slot: number;
    approver: UserRef & { role: Role };
    status: ApprovalStatus;
    channel: 'web' | 'telegram' | null;
    decided_at: string | null;
    reject_reason: DecisionReason | null;
    comment: string | null;
    reminders_sent: number;
    can_decide: boolean;
    can_reassign: boolean;
};
export type RouteStatus =
    | 'active'
    | 'completed'
    | 'rejected'
    | 'expired'
    | 'cancelled'
    | 'superseded';
export type RouteDto = {
    id: number;
    attempt: number;
    status: RouteStatus;
    approvals_required: number;
    required_roles: Role[];
    rules: Array<{ id: number; name: string; version: number }>;
    started_at: string;
    expires_at: string;
    escalated_at: string | null;
    solo_mode: boolean;
    incomplete: boolean;
    approvals: ApprovalDto[];
};
export type ChecklistKey =
    | 'account_last4'
    | 'amount'
    | 'account_holder'
    | 'change_initiated_by_vendor'
    | 'invoice_number';
export type VerificationDto = {
    id: number;
    method: VerificationMethod;
    outcome: VerificationOutcome;
    occurred_at: string;
    recorded_at: string;
    performed_by: UserRef;
    spoken_to_name: string;
    contact: {
        id: number;
        name: string;
        phone_last4: string;
        source: ContactSource;
        source_ref: string | null;
    } | null;
    checklist: Record<ChecklistKey, boolean>;
    notes: string | null;
    sod_waived: boolean;
    satisfies: boolean;
};
export type TimelineItem = {
    id: number;
    seq: number;
    event: string;
    label: string;
    actor: UserRef | null;
    channel: Channel;
    occurred_at: string;
    details: Record<string, string | number | boolean | null>;
    hash: string;
};
export type DeliveryDto = {
    id: number;
    channel: 'email' | 'telegram';
    template: string;
    recipient: UserRef | null;
    status: string;
    at: string;
};
export type FileDto = {
    id: number;
    role: 'original' | 'attachment' | 'verification';
    original_name: string;
    mime: string;
    size_bytes: number;
    pages: number | null;
    sha256: string;
    download_url: string | null;
    purged: boolean;
};
export type PreviewPage = { page: number; url: string };
export type PolicyEvaluation = {
    approvals_required: number;
    required_roles: Role[];
    approver_roles: Role[];
    verification_required: boolean;
    expiry_hours: number;
    matched_rules: Array<{ id: number | null; name: string; version: number }>;
    notes: string[];
    amount_base: MoneyDto | null;
    eligible_approvers: number;
};
export type CallbackContact = {
    id: number;
    name: string;
    role_title: string | null;
    phone_display: string;
    source: ContactSource;
    source_ref: string | null;
};
export type CaseRequest = PaymentRequestRow & {
    description: string | null;
    invoice_number: string | null;
    invoice_date: string | null;
    amount_base: MoneyDto | null;
    fx: { rate: string; date: string } | null;
    vendor_match: { method: string | null; confidence: number | null };
    payee: MaskedAccount | null;
    payee_checksum_valid: boolean | null;
    submitted_by: UserRef | null;
    submitted_at: string | null;
    decided_by: UserRef | null;
    decided_at: string | null;
    decision_reason: string | null;
    decision_note: string | null;
    paid_at: string | null;
    payment_reference: string | null;
    prevented: {
        category: 'fraud' | 'duplicate';
        amount: MoneyDto | null;
    } | null;
    extraction_failed: boolean;
    employee_response: 'it_was_me' | 'not_me' | null;
    blocklisted: boolean;
};
export type ExtractionFields = {
    vendor_name?: ExtractedField<string>;
    invoice_number?: ExtractedField<string>;
    invoice_date?: ExtractedField<string>;
    due_date?: ExtractedField<string>;
    amount?: ExtractedField<MoneyDto>;
    payee_last4?: ExtractedField<string>;
};
export type CaseRisk = {
    score: number;
    level: RiskLevel;
    rules_version: string;
    floor_signal: string | null;
    assessed_at: string;
    signals: RiskSignal[];
};
export type CaseAbilities = {
    review: boolean;
    submit: boolean;
    verify: boolean;
    cancel: boolean;
    reopen: boolean;
    revoke: boolean;
    mark_paid: boolean;
    reveal: boolean;
    export: boolean;
    reassign: boolean;
};
export type CaseVerification = {
    required: boolean;
    reasons: string[];
    satisfied: boolean;
    attempts: VerificationDto[];
    callback_contacts: CallbackContact[];
    vendor_has_callback_contact: boolean;
    replaceable_accounts: Array<{ id: number; label: string }>;
};
export type EvidencePacket = {
    id: number;
    status: ExportStatus;
    download_url: string | null;
    chain_verified: boolean | null;
};
export type PaymentRequestShowProps = {
    request: CaseRequest;
    pipeline: PipelineStatus;
    extraction: {
        status: 'succeeded' | 'partial' | 'failed' | null;
        ocr_used: boolean;
        pages: number;
        llm_assisted: boolean;
        fields: ExtractionFields;
        warnings: string[];
    };
    risk: CaseRisk | null;
    bank_diff: BankDiff | null;
    sender: SenderPanelDto | null;
    verification: CaseVerification;
    route: RouteDto | null;
    past_routes: RouteDto[];
    policy: PolicyEvaluation | null;
    timeline: TimelineItem[];
    deliveries: DeliveryDto[];
    files: FileDto[];
    preview_pages: PreviewPage[];
    source_excerpt: string;
    vendor_candidates: Array<
        VendorRef & { confidence: number; method: string }
    >;
    evidence_packet: EvidencePacket | null;
    options: {
        kinds: Option<PaymentRequestKind>[];
        decision_reasons: Option<DecisionReason>[];
        cancel_reasons: Option<CancelReason>[];
        verification_methods: Array<
            Option<VerificationMethod> & { allowed: boolean }
        >;
        reassign_candidates: UserRef[];
    };
    abilities: CaseAbilities;
};

export type DomainShared = {
    counts: {
        review: number;
        awaiting_my_approval: number;
        awaiting_verification: number;
    };
    solo_mode: boolean;
    plan_usage: { payment_requests: Usage };
    base_currency: string;
};

export type RiskWeek = {
    week: string;
    low: number;
    medium: number;
    high: number;
    critical: number;
};
export type DashboardProps = {
    period: { from: string; to: string };
    kpis: {
        open_checks: number;
        awaiting_my_approval: number;
        awaiting_verification: number;
        high_or_critical_30d: number;
        prevented_amount_30d: MoneyDto;
        prevented_cases_30d: number;
        median_decision_hours_30d: number | null;
        verification_compliance_30d: number | null;
    };
    attention: PaymentRequestRow[];
    recent_bank_changes: Array<{
        request: PaymentRequestRow;
        diff: Pick<BankDiff, 'outcome' | 'previous' | 'incoming'>;
    }>;
    charts: {
        risk_by_week: RiskWeek[];
        prevented_vs_approved: Array<{
            month: string;
            prevented_minor: number;
            approved_minor: number;
        }>;
        top_signals: Array<{ code: string; title: string; count: number }>;
        decision_sla: { on_time: number; expired: number; pending: number };
    };
    onboarding: {
        has_vendors: boolean;
        has_callback_contacts: boolean;
        has_rules: boolean;
        telegram_linked: boolean;
        has_second_approver: boolean;
        has_own_domains?: boolean;
    };
};

export type RequestFilters = {
    q?: string;
    status?: PaymentRequestStatus | 'open' | 'decided';
    risk?: RiskLevel;
    kind?: PaymentRequestKind;
    vendor_id?: number | string;
    mine?: boolean | string;
    from?: string;
    to?: string;
};
export type RequestCounts = Record<
    | 'open'
    | 'review'
    | 'awaiting_verification'
    | 'awaiting_approval'
    | 'decided',
    number
>;
export type PaymentRequestIndexProps = {
    requests: Paginated<PaymentRequestRow>;
    filters: RequestFilters | unknown[];
    counts: RequestCounts;
    vendors: VendorRef[];
};
export type PaymentRequestCreateProps = {
    accept: string[];
    maxFiles: number;
    maxSizeMb: number;
    kinds: Option<PaymentRequestKind>[];
    currencies: string[];
    base_currency: string;
    vendors: VendorRef[];
    limit: Usage;
};

export type ApprovalItem = PaymentRequestRow & {
    approval: ApprovalDto;
    route_expires_at: string;
    telegram_allowed: boolean;
    inline_approve?: boolean;
};
export type ApprovalsIndexProps = {
    tab: 'pending' | 'decided';
    items: Paginated<ApprovalItem>;
    counts: { pending: number; due_soon: number };
};

export type VendorRow = VendorRef & {
    legal_name: string | null;
    country: string | null;
    domains: string[];
    callback_contact: {
        name: string;
        phone_last4: string;
        source: ContactSource;
    } | null;
    verified_account: MaskedAccount | null;
    accounts_count: number;
    open_requests: number;
    last_paid_at: string | null;
    recent_change: boolean;
};
export type VendorFilters = {
    q?: string;
    kind?: VendorKind;
    status?: VendorStatus;
    missing_callback?: boolean | string;
};
export type VendorIndexProps = {
    vendors: Paginated<VendorRow>;
    filters: VendorFilters | unknown[];
    stats: {
        total: number;
        missing_callback: number;
        recent_changes: number;
        employees: number;
    };
    limit: Usage;
};
export type VendorFormValues = {
    id: number;
    kind: VendorKind;
    name: string;
    legal_name: string | null;
    aliases: string[];
    tax_id: string | null;
    country: string | null;
    default_currency: string | null;
    status: VendorStatus;
    payment_terms_days: number | null;
    notes: string | null;
    linked_user_id: number | null;
};
export type VendorFormProps = {
    vendor: VendorFormValues | null;
    kinds: Option<VendorKind>[];
    countries: Array<{ code: string; name: string }>;
    currencies: string[];
    members: UserRef[];
};
export type VendorContactDto = {
    id: number;
    name: string;
    role_title: string | null;
    email: string | null;
    phone_display: string | null;
    is_callback: boolean;
    status?: ContactStatus;
    source: ContactSource;
    source_ref: string | null;
    verified_at: string | null;
    can_verify?: boolean;
};
export type VendorAccountDto = MaskedAccount & {
    id: number;
    status: BankAccountStatus;
    currency: string | null;
    source: string;
    valid_from: string | null;
    valid_to: string | null;
    verified_at: string | null;
    verified_by: UserRef | null;
    verification_method: VerificationMethod | null;
    retired_reason: string | null;
    created_by: UserRef;
    created_at: string;
};
export type BlockedAttempt = {
    last4: string;
    bank_country: string | null;
    created_at: string;
    reason: string;
    request_reference: string | null;
};
export type VendorShowProps = {
    vendor: VendorRow & {
        aliases: string[];
        tax_id: string | null;
        default_currency: string | null;
        payment_terms_days: number | null;
        notes: string | null;
        linked_user: UserRef | null;
        created_at: string;
    };
    contacts: VendorContactDto[];
    domains: Array<{
        id: number;
        domain: string;
        unicode: string | null;
        source: string;
    }>;
    accounts: VendorAccountDto[];
    blocked_attempts: BlockedAttempt[];
    requests: PaymentRequestRow[];
    amount_history: Array<{ month: string; amount_minor: number }>;
    abilities: { manage: boolean; verify_bank: boolean; reveal: boolean };
};
export type BlockedItem = {
    id: number;
    scheme: BankScheme;
    last4: string;
    bank_name: string | null;
    bank_country: string | null;
    reason: string;
    created_by: UserRef;
    created_at: string;
    request: { id: number; reference: string } | null;
    note: string | null;
};
export type BlockedAccountsProps = {
    items: Paginated<BlockedItem>;
    abilities: { manage: boolean };
};

export type RuleDto = {
    id: number;
    name: string;
    description: string | null;
    position: number;
    is_active: boolean;
    version: number;
    min_amount: MoneyDto | null;
    max_amount: MoneyDto | null;
    kinds: PaymentRequestKind[] | null;
    min_risk_level: RiskLevel | null;
    applies_to_new_vendor: boolean | null;
    applies_to_bank_change: boolean | null;
    approvals_required: number;
    required_roles: Role[];
    approver_roles: Role[];
    verification_required: boolean;
    expiry_hours: number | null;
    updated_by: UserRef | null;
    updated_at: string;
};
export type PolicyMember = UserRef & {
    role: Role;
    approval_limit: MoneyDto | null;
    monthly_limit: MoneyDto | null;
    approved_this_month: MoneyDto;
    can_approve: boolean;
};
export type PolicySettings = {
    own_domains: string[];
    business_days: number[];
    business_hours_start: string;
    business_hours_end: string;
    quiet_hours_start: string;
    quiet_hours_end: string;
    default_expiry_hours: number;
    reminder_offsets_hours: number[];
    escalate_after_hours: number;
    telegram_approvals_enabled: boolean;
    telegram_approve_max_risk: RiskLevel;
    verification_amount_threshold: MoneyDto;
    verification_amount_threshold_input?: string;
    verification_valid_days: number;
    country?: string | null;
    callback_cooldown_days?: number;
    new_approver_grace_hours?: number;
    ai_extract_assist?: boolean;
};
export type PolicyTab = 'rules' | 'limits' | 'settings' | 'currencies';
export type ExchangeRate = {
    id: number;
    quote_currency: string;
    rate: string;
    effective_date: string;
    source: string;
};
export type PoliciesProps = {
    tab: PolicyTab;
    rules: RuleDto[];
    members: PolicyMember[];
    settings: PolicySettings;
    exchange_rates: ExchangeRate[];
    base_currency: string;
    currencies: string[];
    options: {
        kinds: Option<PaymentRequestKind>[];
        roles: Option<Role>[];
        risk_levels: Option<RiskLevel>[];
    };
    limits: { rules: Usage; telegram_approvals: boolean };
    abilities: { manage: boolean };
};
export type SimulateInput = {
    amount: string;
    currency: string;
    kind?: PaymentRequestKind;
    risk_level?: RiskLevel;
    new_vendor?: boolean;
    bank_change?: boolean;
};

export type HistogramBucket = '<4h' | '4-24h' | '1-2d' | '2-3d' | '>3d';
export type ReportExport = {
    id: number;
    kind: string;
    format: string;
    status: string;
    period_from: string | null;
    period_to: string | null;
    created_at: string;
    sha256?: string | null;
    download_url: string | null;
};
export type PreventedCase = PaymentRequestRow & {
    decided_at: string;
    decision_reason: DecisionReason;
    prevented_amount: MoneyDto | null;
};
export type ReportsProps = {
    period: { from: string; to: string; preset: ReportPreset };
    kpis: {
        checks: number;
        prevented_amount: MoneyDto;
        prevented_cases: number;
        duplicates_prevented: number;
        bank_changes_caught: number;
        legit_changes_verified: number;
        median_decision_hours: number | null;
        verification_compliance: number | null;
        expired_rate: number | null;
    };
    series: {
        checks_by_week: RiskWeek[];
        prevented_by_month: Array<{
            month: string;
            fraud_minor: number;
            duplicate_minor: number;
        }>;
        signals: Array<{
            code: string;
            title: string;
            count: number;
            category: SignalCategory;
        }>;
        decision_time_histogram: Array<{
            bucket: HistogramBucket;
            count: number;
        }>;
        by_kind: Array<{
            kind: PaymentRequestKind;
            count: number;
            prevented_minor: number;
        }>;
    };
    prevented_cases: PreventedCase[];
    exports: ReportExport[];
    chain: {
        last_verified_at: string | null;
        ok: boolean | null;
        entries: number;
    };
    abilities: {
        export: boolean;
        verify_chain: boolean;
        exports_allowed_by_plan: boolean;
    };
};
export type ChainVerification = {
    ok: boolean;
    checked: number;
    first_broken_seq: number | null;
    head_hash: string;
    verified_at: string;
};
export type RevealedAccount = {
    holder: string | null;
    account: string;
    routing: string | null;
    bic: string | null;
};
export type RevealedBankDetails = {
    incoming: RevealedAccount | null;
    previous: RevealedAccount | null;
    char_diff: Array<{ text: string; changed: boolean }> | null;
};

export type EmployeeResponseSummary = {
    company: string;
    employee_first_name: string;
    payee_masked: string;
    bank: string | null;
    reference: string;
    answered: boolean;
    response: 'it_was_me' | 'not_me' | null;
    expired: boolean;
    expires_at: string;
    locale: string;
};
