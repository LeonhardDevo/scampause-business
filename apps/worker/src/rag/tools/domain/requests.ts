import { z } from 'zod';
import { answerPlural, answerText } from '../../domain/answers.js';
import { ToolError, type ChatAction, type Tool, type ToolContext, type ToolResult } from '../types.js';
import { absoluteUrl, domainGet, NotFound, publicUrl, str, toolResult } from './support.js';

export const REFERENCE_RE = /^SP-\d{5}$/;

const requestSchema = z
  .object({
    reference: z.string(),
    title: z.string().nullish(),
    status: z.string().nullish(),
    status_label: z.string().nullish(),
    kind: z.string().nullish(),
    vendor_name: z.string().nullish(),
    amount_formatted: z.string().nullish(),
    risk_level: z.string().nullish(),
    risk_score: z.number().nullish(),
    top_signals: z.array(z.object({ code: z.string(), title: z.string() })).default([]),
    verification: z.object({ required: z.boolean().default(false), satisfied: z.boolean().default(false), last_outcome: z.string().nullish() }).nullish(),
    approvals: z.array(z.object({ approver_name: z.string().nullish(), status: z.string().nullish() })).default([]),
    expires_at: z.string().nullish(),
    url: z.string().nullish(),
  })
  .passthrough();

export class GetPaymentRequestTool implements Tool {
  readonly name = 'get_payment_request';
  readonly description = 'Get the status, risk level, top risk signals, verification and approvals of a payment request by its reference like SP-00042.';
  readonly parameters = {
    type: 'object',
    properties: { reference: { type: 'string', pattern: '^SP-\\d{5}$' } },
    required: ['reference'],
    additionalProperties: false,
  };
  readonly requiredPermission = 'payment_requests.view_all';
  readonly dataTool = true;

  async run(ctx: ToolContext, args: Record<string, unknown>): Promise<ToolResult> {
    const reference = typeof args.reference === 'string' ? args.reference.trim().toUpperCase() : '';
    if (!REFERENCE_RE.test(reference)) throw new ToolError('reference must look like SP-00042');
    const raw = await domainGet<unknown>(ctx, `/payment-requests/by-reference/${reference}`);
    if (raw instanceof NotFound) return toolResult(this.name, { summary: answerText(ctx.locale, 'request.not_found', { reference }), found: false });
    const data = requestSchema.parse(raw);
    const verification = data.verification;
    const state = !verification || !verification.required ? 'not_required' : verification.satisfied ? 'required_satisfied' : 'required_missing';
    const approvals = data.approvals.map((approval) => `${str(approval.approver_name)}: ${str(approval.status)}`);
    const parts = [
      answerText(ctx.locale, 'request.status', {
        reference: data.reference,
        title: str(data.title),
        status: str(data.status_label ?? data.status),
        risk: str(data.risk_level),
        score: str(data.risk_score),
      }),
    ];
    if (data.top_signals.length > 0) parts.push(answerText(ctx.locale, 'request.signals', { signals: data.top_signals.slice(0, 3).map((signal) => signal.title).join('; ') }));
    parts.push(answerText(ctx.locale, 'request.verification', { state: answerText(ctx.locale, `request.verification_states.${state}`) }));
    if (approvals.length > 0) parts.push(answerText(ctx.locale, 'request.approvals', { approvals: approvals.join(', ') }));
    const url = absoluteUrl(ctx, data.url);
    const actions: ChatAction[] = url ? [{ type: 'open_url', label: answerText(ctx.locale, 'actions.open_case'), payload: { url } }] : [];
    return toolResult(
      this.name,
      {
        summary: parts.join(' '),
        found: true,
        request: {
          reference: data.reference,
          title: { untrusted_text: data.title ?? '' },
          status: data.status ?? null,
          kind: data.kind ?? null,
          vendor_name: { untrusted_text: data.vendor_name ?? '' },
          amount: data.amount_formatted ?? null,
          risk_level: data.risk_level ?? null,
          risk_score: data.risk_score ?? null,
          top_signals: data.top_signals,
          verification: verification ?? null,
          approvals,
          expires_at: data.expires_at ?? null,
        },
      },
      actions,
    );
  }
}

const pendingSchema = z.object({
  items: z
    .array(
      z
        .object({
          approval_id: z.number().int(),
          reference: z.string(),
          title: z.string().nullish(),
          amount_formatted: z.string().nullish(),
          risk_level: z.string().nullish(),
          expires_at: z.string().nullish(),
          url: z.string().nullish(),
        })
        .passthrough(),
    )
    .default([]),
});

export class ListMyPendingApprovalsTool implements Tool {
  readonly name = 'list_my_pending_approvals';
  readonly description = "List payment requests waiting for the current user's approval, with deadlines.";
  readonly parameters = { type: 'object', properties: {}, additionalProperties: false };
  readonly requiredPermission = 'payment_requests.approve';
  readonly dataTool = true;

  async run(ctx: ToolContext): Promise<ToolResult> {
    const raw = await domainGet<unknown>(ctx, '/approvals/pending');
    const items = raw instanceof NotFound ? [] : pendingSchema.parse(raw).items;
    const summary =
      items.length === 0
        ? answerText(ctx.locale, 'approvals.empty')
        : answerPlural(ctx.locale, 'approvals.pending', items.length, {
            items: items
              .slice(0, 10)
              .map((item) =>
                answerText(ctx.locale, 'approvals.item', {
                  reference: item.reference,
                  amount: str(item.amount_formatted),
                  risk: str(item.risk_level),
                  expires: str(item.expires_at),
                }),
              )
              .join('; '),
          });
    const actions: ChatAction[] = items.length > 0 ? [{ type: 'open_url', label: answerText(ctx.locale, 'actions.open_approvals'), payload: { url: publicUrl(ctx, '/approvals') } }] : [];
    return toolResult(
      this.name,
      {
        summary,
        items: items.map((item) => ({
          approval_id: item.approval_id,
          reference: item.reference,
          title: { untrusted_text: item.title ?? '' },
          amount: item.amount_formatted ?? null,
          risk_level: item.risk_level ?? null,
          expires_at: item.expires_at ?? null,
        })),
      },
      actions,
    );
  }
}
