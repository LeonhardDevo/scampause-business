import { GrammyError, InlineKeyboard, type Composer } from 'grammy';
import { UpstreamHttpError } from '../../../clients/errors.js';
import type { TelegramContext } from '../../../clients/web.js';
import type { PendingItem } from '../../../domain/client.js';
import { errorFields } from '../../../logger.js';
import { buttonLabel, escapeHtml, isPublicHttpUrl } from '../../format.js';
import { isPrivateChat, replyHtml, replyUnlinked } from '../../helpers.js';
import { ACTION_CALLBACK_PREFIX, type StoredAction } from '../../stores.js';
import type { BotContext, BotModule } from '../../types.js';
import type { ScampauseBotApi } from './api.js';
import { EN, RU } from './messages.js';
import { renderSp, spActionFromStored } from './render.js';
import { SP_TEMPLATES } from './templates.js';

export const PENDING_LIMIT = 10;
const CALLBACK_TEXT_LIMIT = 190;
const REFERENCE_RE = /^(?:([a-z0-9][a-z0-9-]{0,40})\/)?(SP-\d{5})$/i;
const LOCAL_TYPES = new Set(['sp.ws', 'sp.req']);

interface Linked {
  account: TelegramContext;
  api: ScampauseBotApi;
}

async function linked(ctx: BotContext): Promise<Linked | null> {
  if (!isPrivateChat(ctx)) {
    await ctx.reply(ctx.t('sp.private_only'));
    return null;
  }
  const api = ctx.services.scampause;
  if (!api) {
    await replyHtml(ctx, ctx.t('sp.unavailable'));
    return null;
  }
  let account: TelegramContext | null;
  try {
    account = await ctx.account();
  } catch (error) {
    ctx.log.warn(errorFields(error), 'telegram context lookup failed');
    await replyHtml(ctx, ctx.t('sp.unavailable'));
    return null;
  }
  if (!account) {
    await replyUnlinked(ctx);
    return null;
  }
  return { account, api };
}

function owner(ctx: BotContext, account: TelegramContext | null, workspaceId: number | null): StoredAction {
  return {
    type: '',
    payload: {},
    telegram_user_id: ctx.from?.id ?? null,
    chat_id: ctx.chat?.id ?? null,
    workspace_id: workspaceId,
    user_id: account?.user.id ?? null,
    locale: ctx.locale,
    created_at: Date.now(),
  };
}

async function storeLocal(ctx: BotContext, base: StoredAction, type: string, payload: Record<string, unknown>): Promise<string> {
  const id = await ctx.services.actions.put({ ...base, type, payload, created_at: Date.now() }, ctx.services.settings.actionTtlS);
  return `${ACTION_CALLBACK_PREFIX}${id}`;
}

function pendingLine(ctx: BotContext, item: PendingItem): string {
  return ctx.t('sp.pending.item', {
    reference: escapeHtml(item.reference),
    vendor: escapeHtml(item.vendor ?? '—'),
    amount: escapeHtml(item.amount_formatted ?? '—'),
    risk: escapeHtml(item.risk_label ?? item.risk_level ?? '—'),
    expires: escapeHtml(item.expires_human ?? item.expires_at ?? '—'),
  });
}

async function handlePending(ctx: BotContext): Promise<void> {
  const session = await linked(ctx);
  if (!session || !ctx.from) return;
  let items: PendingItem[];
  try {
    items = await session.api.telegramPending(ctx.from.id, ctx.requestId);
  } catch (error) {
    ctx.log.warn(errorFields(error), 'pending approvals lookup failed');
    await replyHtml(ctx, ctx.t('sp.unavailable'));
    return;
  }
  if (items.length === 0) {
    await replyHtml(ctx, ctx.t('sp.pending.empty'));
    return;
  }
  const shown = items.slice(0, PENDING_LIMIT);
  const lines = [`<b>${ctx.services.translator.plural(ctx.locale, 'sp.pending.header', items.length)}</b>`];
  const keyboard = new InlineKeyboard();
  let buttons = 0;
  let workspace: number | null = null;
  const multiWorkspace = new Set(shown.map((item) => item.workspace.id)).size > 1;
  for (const item of shown) {
    if (multiWorkspace && item.workspace.id !== workspace) {
      lines.push('', ctx.t('sp.pending.workspace', { workspace: escapeHtml(item.workspace.name) }));
      workspace = item.workspace.id;
    }
    lines.push(pendingLine(ctx, item));
    const url = item.url ?? null;
    if (url && isPublicHttpUrl(url)) {
      keyboard.url(buttonLabel(ctx.t('sp.pending.open', { reference: item.reference })), url).row();
      buttons++;
    } else if (url) {
      lines.push(ctx.t('sp.pending.link', { url: escapeHtml(url) }));
    }
  }
  if (items.length > shown.length) lines.push('', ctx.t('sp.pending.more', { count: items.length - shown.length }));
  await replyHtml(ctx, lines.join('\n'), buttons > 0 ? keyboard : undefined);
}

async function showRequest(ctx: BotContext, session: Linked, query: string): Promise<void> {
  if (!ctx.from) return;
  const match = REFERENCE_RE.exec(query.trim());
  if (!match) {
    await replyHtml(ctx, ctx.t('sp.request.usage'));
    return;
  }
  const reference = `${match[1] ? `${match[1].toLowerCase()}/` : ''}${(match[2] ?? '').toUpperCase()}`;
  let lookup;
  try {
    lookup = await session.api.telegramRequest(ctx.from.id, reference, ctx.requestId);
  } catch (error) {
    ctx.log.warn(errorFields(error), 'request lookup failed');
    await replyHtml(ctx, ctx.t('sp.unavailable'));
    return;
  }
  if (!lookup) {
    await replyHtml(ctx, ctx.t('sp.request.not_found', { reference: escapeHtml(reference) }));
    return;
  }
  if ('ambiguous' in lookup) {
    const keyboard = new InlineKeyboard();
    const base = owner(ctx, session.account, session.account.workspace?.id ?? null);
    for (const option of lookup.ambiguous.slice(0, 8)) {
      const value = option.workspace.slug ? `${option.workspace.slug}/${option.reference}` : option.reference;
      keyboard.text(buttonLabel(`${option.workspace.name} · ${option.reference}`), await storeLocal(ctx, { ...base, workspace_id: option.workspace.id }, 'sp.req', { reference: value })).row();
    }
    await replyHtml(ctx, ctx.t('sp.request.choose', { reference: escapeHtml(reference) }), keyboard);
    return;
  }
  const rendered = await renderSp({
    registry: ctx.services.registry,
    translator: ctx.services.translator,
    actions: ctx.services.actions,
    ttlS: ctx.services.settings.actionTtlS,
    locale: ctx.locale,
    template: lookup.template,
    params: lookup.params,
    spActions: lookup.actions,
    owner: { telegramUserId: ctx.from.id, chatId: ctx.chat?.id ?? null, workspaceId: session.account.workspace?.id ?? null, userId: session.account.user.id },
  });
  await replyHtml(ctx, rendered.html, rendered.keyboard);
}

async function handleRequest(ctx: BotContext): Promise<void> {
  const session = await linked(ctx);
  if (!session) return;
  await showRequest(ctx, session, typeof ctx.match === 'string' ? ctx.match : '');
}

async function handleStop(ctx: BotContext): Promise<void> {
  if (!isPrivateChat(ctx)) {
    await ctx.reply(ctx.t('sp.private_only'));
    return;
  }
  await replyHtml(ctx, ctx.t('sp.tpl.stop_card'));
}

async function handleWorkspace(ctx: BotContext): Promise<void> {
  const session = await linked(ctx);
  if (!session || !ctx.from) return;
  let response;
  try {
    response = await session.api.telegramWorkspace(ctx.from.id, null, ctx.requestId);
  } catch (error) {
    ctx.log.warn(errorFields(error), 'workspace list failed');
    await replyHtml(ctx, ctx.t('sp.unavailable'));
    return;
  }
  const workspaces = 'workspaces' in response ? response.workspaces : [];
  if (workspaces.length === 0) {
    await replyHtml(ctx, ctx.t('sp.workspace.none'));
    return;
  }
  const keyboard = new InlineKeyboard();
  const base = owner(ctx, session.account, null);
  for (const workspace of workspaces.slice(0, 20)) {
    const label = workspace.current ? ctx.t('sp.workspace.current', { name: workspace.name }) : workspace.name;
    keyboard.text(buttonLabel(label), await storeLocal(ctx, { ...base, workspace_id: workspace.id }, 'sp.ws', { workspace_id: workspace.id, name: workspace.name })).row();
  }
  await replyHtml(ctx, ctx.t('sp.workspace.choose'), keyboard);
}

async function answer(ctx: BotContext, text: string, alert: boolean): Promise<void> {
  await ctx.answerCallbackQuery({ text: text.slice(0, CALLBACK_TEXT_LIMIT), show_alert: alert }).catch(() => undefined);
}

async function switchWorkspace(ctx: BotContext, api: ScampauseBotApi, action: StoredAction): Promise<void> {
  if (!ctx.from) return;
  const workspaceId = typeof action.payload.workspace_id === 'number' ? action.payload.workspace_id : null;
  if (workspaceId === null) {
    await answer(ctx, ctx.t('sp.button_invalid'), true);
    return;
  }
  await api.telegramWorkspace(ctx.from.id, workspaceId, ctx.requestId);
  await ctx.services.accounts.invalidate(ctx.from.id);
  await answer(ctx, ctx.t('actions.done'), false);
  const name = typeof action.payload.name === 'string' ? action.payload.name : String(workspaceId);
  const html = ctx.t('sp.workspace.switched', { name: escapeHtml(name) });
  try {
    await ctx.editMessageText(html, { parse_mode: 'HTML' });
  } catch {
    await replyHtml(ctx, html);
  }
}

function errorCode(error: unknown): string | null {
  if (!(error instanceof UpstreamHttpError)) return null;
  const body = error.body as { error?: unknown; message?: unknown } | null;
  return body && typeof body === 'object' && typeof body.error === 'string' ? body.error : null;
}

function errorMessageOf(error: unknown): string | null {
  if (!(error instanceof UpstreamHttpError)) return null;
  const body = error.body as { message?: unknown } | null;
  return body && typeof body === 'object' && typeof body.message === 'string' && body.message.trim() !== '' ? body.message : null;
}

async function showSpec(ctx: BotContext, action: StoredAction, spec: { template: string; params: Record<string, unknown>; actions: StoredSpecAction[] }, mode: 'edit' | 'send'): Promise<void> {
  const rendered = await renderSp({
    registry: ctx.services.registry,
    translator: ctx.services.translator,
    actions: ctx.services.actions,
    ttlS: ctx.services.settings.actionTtlS,
    locale: ctx.locale,
    template: spec.template,
    params: spec.params,
    spActions: spec.actions,
    owner: { telegramUserId: ctx.from?.id ?? null, chatId: ctx.chat?.id ?? null, workspaceId: action.workspace_id, userId: action.user_id },
  });
  const options = { parse_mode: 'HTML' as const, link_preview_options: { is_disabled: true }, ...(rendered.keyboard ? { reply_markup: rendered.keyboard } : {}) };
  if (mode === 'edit' && ctx.callbackQuery?.message) {
    try {
      await ctx.editMessageText(rendered.html, options);
      return;
    } catch (error) {
      if (!(error instanceof GrammyError)) throw error;
      ctx.log.info({ description: error.description }, 'telegram edit failed; sending a new message');
    }
  }
  await ctx.reply(rendered.html, options);
}

interface StoredSpecAction {
  type: string;
  subject_id: number;
  step_token: string;
  reason?: string | null;
}

async function handleSpAction(ctx: BotContext, action: StoredAction): Promise<void> {
  const from = ctx.from;
  if (!from) return;
  if (!isPrivateChat(ctx)) {
    await answer(ctx, ctx.t('sp.private_only'), true);
    return;
  }
  const api = ctx.services.scampause;
  if (!api) {
    await answer(ctx, ctx.t('sp.unavailable'), true);
    return;
  }
  try {
    if (action.type === 'sp.ws') {
      await switchWorkspace(ctx, api, action);
      return;
    }
    if (action.type === 'sp.req') {
      await answer(ctx, '', false);
      const account = await ctx.account();
      if (!account) {
        await replyUnlinked(ctx);
        return;
      }
      await showRequest(ctx, { account, api }, typeof action.payload.reference === 'string' ? action.payload.reference : '');
      return;
    }
    const decoded = spActionFromStored(action);
    if (!decoded || action.workspace_id === null) {
      await answer(ctx, ctx.t('sp.button_invalid'), true);
      return;
    }
    const response = await api.telegramCallback(
      {
        action_type: decoded.type,
        subject_id: decoded.subject_id,
        workspace_id: action.workspace_id,
        telegram_user_id: from.id,
        chat_id: ctx.chat?.id ?? from.id,
        message_id: ctx.callbackQuery?.message?.message_id ?? null,
        step_token: decoded.step_token,
        ...(decoded.reason ? { reason: decoded.reason } : {}),
        language_code: ctx.locale,
      },
      ctx.requestId,
    );
    ctx.log.info({ actionType: decoded.type, subjectId: decoded.subject_id, alert: response.answer.show_alert }, 'scampause callback handled');
    await answer(ctx, response.answer.text, response.answer.show_alert);
    if (response.edit) await showSpec(ctx, action, response.edit, 'edit');
    if (response.send) await showSpec(ctx, action, response.send, 'send');
  } catch (error) {
    const code = errorCode(error);
    ctx.log.warn({ actionType: action.type, code, ...errorFields(error) }, 'scampause callback failed');
    const status = error instanceof UpstreamHttpError ? error.status : null;
    if (status !== null && status >= 400 && status < 500) {
      await answer(ctx, errorMessageOf(error) ?? ctx.t('sp.button_invalid'), true);
      return;
    }
    await answer(ctx, ctx.t('sp.unavailable'), true);
  }
}

export const scampauseModule: BotModule = {
  name: 'scampause',
  commands: [
    { command: 'pending', descriptionKey: 'sp.cmd.pending' },
    { command: 'request', descriptionKey: 'sp.cmd.request' },
    { command: 'stop', descriptionKey: 'sp.cmd.stop' },
    { command: 'workspace', descriptionKey: 'sp.cmd.workspace' },
  ],
  helpKeys: ['sp.help.extra', 'sp.tpl.stop_card'],
  privacyKeys: ['sp.privacy.text'],
  messages: { ru: RU, en: EN },
  notifications: SP_TEMPLATES,
  register(composer: Composer<BotContext>) {
    composer.command('pending', handlePending);
    composer.command('request', handleRequest);
    composer.command('stop', handleStop);
    composer.command('workspace', handleWorkspace);
    composer.callbackQuery(/^a:([A-Za-z0-9]{6,32})$/, async (ctx, next) => {
      const action = await ctx.services.actions.get(ctx.match[1] ?? '');
      if (!action || !(action.type.startsWith('sp.') || LOCAL_TYPES.has(action.type))) {
        await next();
        return;
      }
      const from = ctx.from;
      if (action.telegram_user_id !== null && action.telegram_user_id !== from.id) {
        await answer(ctx, ctx.t('sp.forbidden'), true);
        return;
      }
      await handleSpAction(ctx, action);
    });
  },
};

export { renderSp } from './render.js';
export { SP_TEMPLATES } from './templates.js';
export type { ScampauseBotApi } from './api.js';
