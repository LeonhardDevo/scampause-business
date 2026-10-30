import type { NotificationHelpers, NotificationTemplate, NotifyButton } from '../../types.js';

export const TITLE_MAX = 120;

export const REJECT_REASONS = ['fraud_confirmed', 'details_mismatch', 'not_ordered', 'duplicate', 'other'] as const;

export interface SpActionSpec {
  type: string;
  subject_id: number;
  step_token: string;
  reason?: string | null;
}

function raw(params: Record<string, unknown>, key: string): string | null {
  const value = params[key];
  if (typeof value === 'string') return value.trim() === '' ? null : value;
  if (typeof value === 'number' && Number.isFinite(value)) return String(value);
  return null;
}

function text(params: Record<string, unknown>, key: string, h: NotificationHelpers, fallback = '—'): string {
  return h.escape(raw(params, key) ?? fallback);
}

function title(params: Record<string, unknown>, h: NotificationHelpers): string | null {
  const value = raw(params, 'title');
  if (value === null) return null;
  const clean = value.replace(/\s+/g, ' ').trim();
  return h.escape(clean.length > TITLE_MAX ? `${clean.slice(0, TITLE_MAX - 1)}…` : clean);
}

function list(params: Record<string, unknown>, key: string): string[] {
  const value = params[key];
  if (!Array.isArray(value)) return [];
  return (value as unknown[])
    .map((item: unknown): string | null => {
      if (typeof item === 'string') return item;
      if (item && typeof item === 'object') {
        const titleValue = (item as Record<string, unknown>).title;
        if (typeof titleValue === 'string') return titleValue;
      }
      return null;
    })
    .filter((item): item is string => item !== null && item.trim() !== '');
}

function bullets(params: Record<string, unknown>, key: string, h: NotificationHelpers, max = 5): string | null {
  const items = list(params, key).slice(0, max);
  return items.length === 0 ? null : items.map((item) => `• ${h.escape(item)}`).join('\n');
}

function joined(params: Record<string, unknown>, key: string, h: NotificationHelpers): string {
  const items = list(params, key);
  if (items.length > 0) return h.escape(items.join(', '));
  return text(params, key, h);
}

function workspaceLine(params: Record<string, unknown>, h: NotificationHelpers): string | null {
  const workspace = raw(params, 'workspace') ?? raw(params, 'workspace_name');
  return workspace === null ? null : `<i>${h.escape(workspace)}</i>`;
}

export function encodeSpCallback(action: SpActionSpec): string {
  const reason = action.reason ? `:${action.reason}` : '';
  return `${action.type}:${action.subject_id}:${action.step_token}${reason}`;
}

export function actionLabel(action: Pick<SpActionSpec, 'type' | 'reason'>, h: Pick<NotificationHelpers, 't'>): string {
  switch (action.type) {
    case 'sp.apv':
      return h.t('sp.btn.approve');
    case 'sp.apv_confirm':
      return h.t('sp.btn.confirm_approval');
    case 'sp.rej':
      return h.t('sp.btn.reject');
    case 'sp.rej_reason':
      return h.t(`sp.btn.reason.${action.reason ?? 'other'}`);
    case 'sp.cancel':
      return h.t('sp.btn.cancel');
    case 'sp.pay_me':
      return h.t('sp.btn.pay_me');
    case 'sp.pay_not_me':
      return h.t('sp.btn.pay_not_me');
    default:
      return h.t('sp.btn.open');
  }
}

export function parseActions(params: Record<string, unknown>): SpActionSpec[] {
  const value = params.actions;
  if (!Array.isArray(value)) return [];
  const out: SpActionSpec[] = [];
  for (const item of value) {
    if (!item || typeof item !== 'object') continue;
    const record = item as Record<string, unknown>;
    const type = typeof record.type === 'string' ? record.type : null;
    const subject = typeof record.subject_id === 'number' ? record.subject_id : Number(record.subject_id);
    if (!type || !type.startsWith('sp.') || !Number.isSafeInteger(subject) || subject <= 0) continue;
    out.push({
      type,
      subject_id: subject,
      step_token: typeof record.step_token === 'string' ? record.step_token : '',
      reason: typeof record.reason === 'string' ? record.reason : null,
    });
  }
  return out;
}

function buttons(params: Record<string, unknown>, h: NotificationHelpers, urlLabelKey = 'sp.btn.open'): NotifyButton[] {
  const out: NotifyButton[] = parseActions(params).map((action) => ({ text: actionLabel(action, h), callback: encodeSpCallback(action) }));
  const url = raw(params, 'url');
  if (url) out.push({ text: h.t(urlLabelKey), url });
  return out;
}

function compose(lines: Array<string | null>): string {
  return lines.filter((line): line is string => line !== null && line !== '').join('\n');
}

function verificationStatus(params: Record<string, unknown>, h: NotificationHelpers): string {
  const status = raw(params, 'verification_status');
  const verification = params.verification;
  if (verification && typeof verification === 'object') {
    const record = verification as Record<string, unknown>;
    const state = typeof record.state === 'string' ? record.state : typeof record.status === 'string' ? record.status : null;
    if (state === 'confirmed') {
      return h.t('sp.tpl.approval_requested.verification_confirmed', {
        verifier: h.escape(typeof record.verifier === 'string' ? record.verifier : '—'),
        when: h.escape(typeof record.when === 'string' ? record.when : '—'),
      });
    }
    if (state === 'not_required') return h.t('sp.tpl.approval_requested.verification_not_required');
    return h.t('sp.tpl.approval_requested.verification_pending');
  }
  if (status === 'not_required') return h.t('sp.tpl.approval_requested.verification_not_required');
  if (status === 'pending') return h.t('sp.tpl.approval_requested.verification_pending');
  return status === null ? h.t('sp.tpl.approval_requested.verification_pending') : h.escape(status);
}

function summaryLines(params: Record<string, unknown>, h: NotificationHelpers): string[] {
  const due = raw(params, 'due_date');
  return [
    title(params, h),
    h.t('sp.tpl.approval_requested.payee', { vendor: text(params, 'vendor', h), payee: text(params, 'payee_masked', h) }),
    due
      ? h.t('sp.tpl.approval_requested.amount', { amount: text(params, 'amount', h), due_date: h.escape(due) })
      : h.t('sp.tpl.approval_requested.amount_no_due', { amount: text(params, 'amount', h) }),
    h.t('sp.tpl.approval_requested.risk', { risk_label: text(params, 'risk_label', h), score: text(params, 'score', h) }),
    bullets(params, 'top_signals', h),
    h.t('sp.tpl.approval_requested.verification', { status: verificationStatus(params, h) }),
  ].filter((line): line is string => line !== null);
}

const approvalRequested: NotificationTemplate = (params, h) => ({
  html: compose([
    workspaceLine(params, h),
    `<b>${h.t('sp.tpl.approval_requested.title', { reference: text(params, 'reference', h) })}</b>`,
    ...summaryLines(params, h),
    h.t('sp.tpl.approval_requested.deadline', { expires_local: text(params, 'expires_local', h), expires_relative: text(params, 'expires_relative', h) }),
    `<i>${h.t('sp.disclaimer')}</i>`,
  ]),
  buttons: buttons(params, h),
});

const approveConfirm: NotificationTemplate = (params, h) => ({
  html: compose([
    workspaceLine(params, h),
    h.t('sp.tpl.approve_confirm', {
      reference: text(params, 'reference', h),
      amount: text(params, 'amount', h),
      vendor: text(params, 'vendor', h),
      payee: text(params, 'payee_masked', h),
    }),
  ]),
  buttons: buttons(params, h),
});

const approveBlocked: NotificationTemplate = (params, h) => {
  const reasonKey = raw(params, 'reason');
  const reason =
    reasonKey === 'bank_change' || reasonKey === 'high_risk' || reasonKey === 'plan'
      ? h.t(`sp.tpl.blocked_reason.${reasonKey}`)
      : h.escape(reasonKey ?? '—');
  return { html: h.t('sp.tpl.telegram_approve_blocked', { reason }), buttons: buttons(params, h, 'sp.btn.open_case') };
};

const rejectReasonPrompt: NotificationTemplate = (params, h) => ({
  html: compose([workspaceLine(params, h), h.t('sp.tpl.reject_reason_prompt', { reference: text(params, 'reference', h) })]),
  buttons: buttons(params, h),
});

const approvedAck: NotificationTemplate = (params, h) => ({
  html: compose([
    workspaceLine(params, h),
    `<b>${text(params, 'reference', h, '')}</b>`,
    h.t('sp.tpl.approved_ack', { time_local: text(params, 'time_local', h), remaining: text(params, 'remaining', h, '0') }),
  ]),
  buttons: buttons(params, h),
});

const rejectedAck: NotificationTemplate = (params, h) => {
  const blocked = params.blocked === true;
  return {
    html: compose([
      workspaceLine(params, h),
      `<b>${text(params, 'reference', h, '')}</b>`,
      h.t(blocked ? 'sp.tpl.rejected_ack_blocked' : 'sp.tpl.rejected_ack', { reason_label: text(params, 'reason_label', h) }),
    ]),
    buttons: buttons(params, h),
  };
};

const approvalReminder: NotificationTemplate = (params, h) => ({
  html: compose([
    workspaceLine(params, h),
    h.t('sp.tpl.approval_reminder', {
      n: text(params, 'n', h, '1'),
      total: text(params, 'total', h, '1'),
      reference: text(params, 'reference', h),
      expires_relative: text(params, 'expires_relative', h),
    }),
    ...summaryLines(params, h),
    `<i>${h.t('sp.disclaimer')}</i>`,
  ]),
  buttons: buttons(params, h),
});

const approvalEscalation: NotificationTemplate = (params, h) => ({
  html: compose([
    workspaceLine(params, h),
    h.t('sp.tpl.approval_escalation', {
      reference: text(params, 'reference', h),
      amount: text(params, 'amount', h),
      hours: text(params, 'hours', h),
      approvers: joined(params, 'approvers', h),
      expires_local: text(params, 'expires_local', h),
    }),
  ]),
  buttons: buttons(params, h),
});

const requestExpired: NotificationTemplate = (params, h) => ({
  html: compose([workspaceLine(params, h), h.t('sp.tpl.request_expired', { reference: text(params, 'reference', h) })]),
  buttons: buttons(params, h, 'sp.btn.reopen'),
});

const riskDetected: NotificationTemplate = (params, h) => ({
  html: compose([
    workspaceLine(params, h),
    `<b>${h.t('sp.tpl.risk_detected.title', { risk_label: text(params, 'risk_label', h), reference: text(params, 'reference', h) })}</b>`,
    title(params, h),
    bullets(params, 'top_signals', h),
    h.t('sp.tpl.risk_detected.advice'),
  ]),
  buttons: buttons(params, h, 'sp.btn.open_case'),
});

const reviewReady: NotificationTemplate = (params, h) => ({
  html: compose([
    workspaceLine(params, h),
    h.t('sp.tpl.review_ready', { reference: text(params, 'reference', h), risk_label: text(params, 'risk_label', h) }),
    title(params, h),
  ]),
  buttons: buttons(params, h),
});

const verificationRequired: NotificationTemplate = (params, h) => {
  const contact = raw(params, 'callback_contact');
  const reasons = joined(params, 'reasons', h);
  const reference = text(params, 'reference', h);
  return {
    html: compose([
      workspaceLine(params, h),
      contact
        ? h.t('sp.tpl.verification_required', { reference, reasons, callback_contact: h.escape(contact), source_ref: text(params, 'source_ref', h) })
        : h.t('sp.tpl.verification_no_contact', { reference, reasons }),
    ]),
    buttons: buttons(params, h, 'sp.btn.open_verification'),
  };
};

const payrollAlert: NotificationTemplate = (params, h) => ({
  html: compose([
    h.t('sp.tpl.payroll_employee_alert', {
      employee_first_name: text(params, 'employee_first_name', h),
      company: text(params, 'company', h),
      payee: text(params, 'payee_masked', h),
      bank: text(params, 'bank', h),
    }),
  ]),
  buttons: buttons(params, h),
});

const payrollAnswer: NotificationTemplate = (params, h) => ({
  html: h.t(params.response === 'not_me' ? 'sp.tpl.payroll_answer_not_me' : 'sp.tpl.payroll_answer_me'),
});

const requestApproved: NotificationTemplate = (params, h) => ({
  html: compose([workspaceLine(params, h), h.t('sp.tpl.request_approved', { reference: text(params, 'reference', h), approvers: joined(params, 'approvers', h) })]),
  buttons: buttons(params, h),
});

const requestRejected: NotificationTemplate = (params, h) => {
  const amount = raw(params, 'prevented_amount');
  const prevented = amount ? h.t('sp.tpl.prevented_line', { amount: h.escape(amount) }) : '';
  return {
    html: compose([
      workspaceLine(params, h),
      h.t('sp.tpl.request_rejected', {
        reference: text(params, 'reference', h),
        reason_label: text(params, 'reason_label', h),
        actor: text(params, 'actor', h),
        prevented_line: prevented,
      }).trim(),
    ]),
    buttons: buttons(params, h),
  };
};

const routeIncomplete: NotificationTemplate = (params, h) => ({
  html: compose([
    workspaceLine(params, h),
    h.t('sp.tpl.route_incomplete', { reference: text(params, 'reference', h), required: text(params, 'required', h), available: text(params, 'available', h) }),
  ]),
  buttons: buttons(params, h, 'sp.btn.open_case'),
});

const chainBroken: NotificationTemplate = (params, h) => ({
  html: h.t('sp.tpl.evidence_chain_broken', { workspace: text(params, 'workspace', h), seq: text(params, 'seq', h) }),
  buttons: buttons(params, h, 'sp.btn.open_reports'),
});

const requestStatus: NotificationTemplate = (params, h) => {
  const expires = raw(params, 'expires_local');
  const verification = raw(params, 'verification');
  const approvals = list(params, 'approvals');
  return {
    html: compose([
      workspaceLine(params, h),
      `<b>${h.t('sp.tpl.request_status.title', { reference: text(params, 'reference', h), status: text(params, 'status_label', h) })}</b>`,
      title(params, h),
      h.t('sp.tpl.request_status.vendor', { vendor: text(params, 'vendor', h) }),
      h.t('sp.tpl.request_status.amount', { amount: text(params, 'amount', h) }),
      h.t('sp.tpl.request_status.risk', { risk_label: text(params, 'risk_label', h), score: text(params, 'score', h) }),
      bullets(params, 'top_signals', h, 3),
      verification ? h.t('sp.tpl.request_status.verification', { verification: h.escape(verification) }) : null,
      approvals.length > 0 ? h.t('sp.tpl.request_status.approvals', { approvals: h.escape(approvals.join(', ')) }) : null,
      expires ? h.t('sp.tpl.request_status.deadline', { expires_local: h.escape(expires) }) : null,
      `<i>${h.t('sp.disclaimer')}</i>`,
    ]),
    buttons: buttons(params, h),
  };
};

const stopCard: NotificationTemplate = (_params, h) => ({ html: h.t('sp.tpl.stop_card') });

export const SP_TEMPLATES: Readonly<Record<string, NotificationTemplate>> = Object.freeze({
  'sp.approval_requested': approvalRequested,
  'sp.approve_confirm': approveConfirm,
  'sp.telegram_approve_blocked': approveBlocked,
  'sp.reject_reason_prompt': rejectReasonPrompt,
  'sp.approved_ack': approvedAck,
  'sp.rejected_ack': rejectedAck,
  'sp.approval_reminder': approvalReminder,
  'sp.approval_escalation': approvalEscalation,
  'sp.request_expired': requestExpired,
  'sp.risk_detected': riskDetected,
  'sp.review_ready': reviewReady,
  'sp.verification_required': verificationRequired,
  'sp.payroll_employee_alert': payrollAlert,
  'sp.payroll_answer': payrollAnswer,
  'sp.request_approved': requestApproved,
  'sp.request_rejected': requestRejected,
  'sp.route_incomplete': routeIncomplete,
  'sp.evidence_chain_broken': chainBroken,
  'sp.request_status': requestStatus,
  'sp.stop_card': stopCard,
});
