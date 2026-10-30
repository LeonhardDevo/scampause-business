import { z } from 'zod';
import { formatMoney, parseMajorToMinor } from '../../../domain/money.js';
import { answerPlural, answerText } from '../../domain/answers.js';
import { ToolError, type Tool, type ToolContext, type ToolResult } from '../types.js';
import { domainPost, toolResult } from './support.js';

const ROLE_ORDER = ['owner', 'admin', 'approver'];

const moneySchema = z.object({ minor: z.union([z.number(), z.string()]), currency: z.string(), formatted: z.string().nullish() }).passthrough();

export const policyEvaluationSchema = z
  .object({
    approvals_required: z.number().int().min(0),
    required_roles: z.array(z.string()).default([]),
    approver_roles: z.array(z.string()).default([]),
    verification_required: z.boolean().default(false),
    expiry_hours: z.number().nullish(),
    matched_rules: z.array(z.object({ id: z.number().nullish(), name: z.string(), version: z.number().nullish() }).passthrough()).default([]),
    notes: z.array(z.string()).default([]),
    amount: moneySchema.nullish(),
  })
  .passthrough();
export type PolicyEvaluation = z.infer<typeof policyEvaluationSchema>;

const argsSchema = z
  .object({
    amount: z.string().regex(/^\d{1,12}([.,]\d{1,2})?$/),
    currency: z.string().regex(/^[A-Z]{3}$/).optional(),
    kind: z.enum(['invoice', 'bank_details_change', 'urgent_wire', 'payroll_change', 'qr_payment', 'other']).optional(),
    new_vendor: z.boolean().optional(),
    bank_change: z.boolean().optional(),
    risk_level: z.enum(['low', 'medium', 'high', 'critical']).optional(),
  })
  .strict();

export function policySummary(evaluation: PolicyEvaluation, amountText: string, locale: 'ru' | 'en'): string {
  const roles = [...evaluation.required_roles].sort((a, b) => ROLE_ORDER.indexOf(a) - ROLE_ORDER.indexOf(b));
  const roleNames = roles.map((role) => answerText(locale, `roles.${role}`));
  const rule = evaluation.matched_rules.map((item) => item.name).join(', ') || answerText(locale, 'policy.default_rule');
  const parts = [
    answerText(locale, 'policy.approvals_needed', {
      amount: amountText,
      approvers: answerPlural(locale, 'policy.approvers', evaluation.approvals_required),
      roles: roleNames.length > 0 ? answerText(locale, 'policy.roles', { roles: roleNames.join(', ') }) : '',
      verification: answerText(locale, evaluation.verification_required ? 'policy.verification_required' : 'policy.verification_not_required'),
      rule,
    }),
  ];
  if (typeof evaluation.expiry_hours === 'number') parts.push(answerText(locale, 'policy.deadline', { hours: evaluation.expiry_hours }));
  if (evaluation.notes.length > 0) parts.push(answerText(locale, 'policy.notes', { notes: evaluation.notes.join('; ') }));
  return parts.join(' ');
}

export class EvaluateApprovalPolicyTool implements Tool {
  readonly name = 'evaluate_approval_policy';
  readonly description =
    "Evaluate this workspace's payment approval rules for a hypothetical payment. Returns how many approvers are required, which roles, whether a callback verification (Stop. Call. Confirm.) is required, and the approval deadline. Use for questions like 'Do I need a second approver for $4,000?'.";
  readonly parameters = {
    type: 'object',
    properties: {
      amount: { type: 'string', pattern: '^\\d{1,12}([.,]\\d{1,2})?$', description: 'Amount in major units, e.g. 4000 or 4000.00' },
      currency: { type: 'string', pattern: '^[A-Z]{3}$', description: 'ISO 4217; default = workspace currency' },
      kind: { type: 'string', enum: ['invoice', 'bank_details_change', 'urgent_wire', 'payroll_change', 'qr_payment', 'other'] },
      new_vendor: { type: 'boolean' },
      bank_change: { type: 'boolean' },
      risk_level: { type: 'string', enum: ['low', 'medium', 'high', 'critical'] },
    },
    required: ['amount'],
    additionalProperties: false,
  };
  readonly requiredPermission = 'policies.view';
  readonly dataTool = true;

  async run(ctx: ToolContext, args: Record<string, unknown>): Promise<ToolResult> {
    const parsed = argsSchema.safeParse(args);
    if (!parsed.success) throw new ToolError(`invalid arguments: ${z.prettifyError(parsed.error)}`);
    const input = parsed.data;
    const raw = await domainPost<unknown>(ctx, '/policy/evaluate', { ...input });
    const evaluation = policyEvaluationSchema.safeParse(raw);
    if (!evaluation.success) throw new ToolError('the policy service returned an unexpected response');
    const result = evaluation.data;
    let amountText = input.amount;
    if (result.amount) {
      amountText = formatMoney(BigInt(String(result.amount.minor)), result.amount.currency, ctx.locale);
    } else if (input.currency) {
      const minor = parseMajorToMinor(input.amount.replace(',', '.'), input.currency);
      if (minor !== null) amountText = formatMoney(minor, input.currency, ctx.locale);
    }
    return toolResult(this.name, {
      summary: policySummary(result, amountText, ctx.locale),
      evaluation: {
        approvals_required: result.approvals_required,
        required_roles: result.required_roles,
        approver_roles: result.approver_roles,
        verification_required: result.verification_required,
        expiry_hours: result.expiry_hours ?? null,
        matched_rules: result.matched_rules.map((rule) => ({ id: rule.id ?? null, name: { untrusted_text: rule.name }, version: rule.version ?? null })),
        notes: result.notes,
        amount: amountText,
      },
    });
  }
}
