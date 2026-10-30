import { UpstreamHttpError } from '../../../clients/errors.js';
import type { QueryValue } from '../../../clients/internalFetch.js';
import { ToolError, type ChatAction, type ToolContext, type ToolResult } from '../types.js';

export const DOMAIN_API_PREFIX = '/internal/v1/scampause';
export const TOOL_CONFIDENCE = 0.9;

export class NotFound {
  readonly notFound = true;
}

function translate(error: unknown): unknown {
  if (error instanceof UpstreamHttpError) {
    if (error.status === 403) return new ToolError('forbidden: the current user may not access this data');
    if (error.status === 422) return new ToolError('invalid arguments');
  }
  return error;
}

function identity(ctx: ToolContext): Record<string, QueryValue> {
  return { workspace_id: ctx.workspaceId, user_id: ctx.user.id };
}

export async function domainGet<T>(ctx: ToolContext, path: string, query: Record<string, QueryValue> = {}): Promise<T | NotFound> {
  try {
    return await ctx.web.getJson<T>(`${DOMAIN_API_PREFIX}${path}`, { ...identity(ctx), ...query }, ctx.requestId ?? undefined);
  } catch (error) {
    if (error instanceof UpstreamHttpError && error.status === 404) return new NotFound();
    throw translate(error);
  }
}

export async function domainPost<T>(ctx: ToolContext, path: string, body: Record<string, unknown>): Promise<T> {
  try {
    return await ctx.web.postJson<T>(`${DOMAIN_API_PREFIX}${path}`, { ...identity(ctx), ...body }, ctx.requestId ?? undefined);
  } catch (error) {
    throw translate(error);
  }
}

export function toolResult(name: string, payload: Record<string, unknown>, actions: ChatAction[] = []): ToolResult {
  return {
    content: `<tool_result name="${name}">${JSON.stringify(payload)}</tool_result>`,
    hasData: true,
    confidence: TOOL_CONFIDENCE,
    actions,
  };
}

export function publicUrl(ctx: ToolContext, path: string): string {
  return `${ctx.publicWebUrl.replace(/\/+$/, '')}${path.startsWith('/') ? path : `/${path}`}`;
}

export function absoluteUrl(ctx: ToolContext, url: string | null | undefined): string | null {
  if (!url) return null;
  if (/^https?:\/\//i.test(url)) return url.startsWith(ctx.publicWebUrl) ? url : null;
  return url.startsWith('/') ? publicUrl(ctx, url) : null;
}

export function integerArg(args: Record<string, unknown>, key: string): number {
  const value = typeof args[key] === 'number' ? args[key] : Number(args[key]);
  if (!Number.isSafeInteger(value) || value <= 0) throw new ToolError(`${key} must be a positive integer`);
  return value;
}

export function str(value: unknown, fallback = '—'): string {
  if (typeof value === 'string' && value.trim() !== '') return value;
  if (typeof value === 'number') return String(value);
  return fallback;
}
