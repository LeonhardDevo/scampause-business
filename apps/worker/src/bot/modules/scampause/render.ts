import { InlineKeyboard } from 'grammy';
import type { Locale, Translator } from '../../../i18n/index.js';
import { buttonLabel, escapeHtml, isPublicHttpUrl, toTelegramHtml } from '../../format.js';
import { ACTION_CALLBACK_PREFIX, type ActionStore, type StoredAction } from '../../stores.js';
import type { BotRegistry, NotifyButton } from '../../types.js';
import type { SpActionSpec } from './templates.js';

export interface SpOwner {
  telegramUserId: number | null;
  chatId: number | null;
  workspaceId: number | null;
  userId: number | null;
}

export interface RenderSpInput {
  registry: BotRegistry;
  translator: Translator;
  actions: ActionStore;
  ttlS: number;
  locale: Locale;
  template: string;
  params: Record<string, unknown>;
  spActions?: readonly SpActionSpec[];
  owner: SpOwner;
}

export interface RenderedSp {
  html: string;
  keyboard: InlineKeyboard | undefined;
}

export class UnknownTemplateError extends Error {
  readonly retryable = false;

  constructor(name: string) {
    super(`Unknown notification template "${name}"`);
    this.name = 'UnknownTemplateError';
  }
}

export interface DecodedSpCallback {
  type: string;
  subject_id: number;
  step_token: string;
  reason: string | null;
}

export function decodeSpCallback(value: string): DecodedSpCallback | null {
  const parts = value.split(':');
  const type = parts[0] ?? '';
  const subject = Number(parts[1]);
  if (!type.startsWith('sp.') || !Number.isSafeInteger(subject) || subject <= 0) return null;
  return { type, subject_id: subject, step_token: parts[2] ?? '', reason: parts[3] && parts[3] !== '' ? parts[3] : null };
}

export function spActionFromStored(action: StoredAction): DecodedSpCallback | null {
  const payload = action.payload;
  if (typeof payload.subject_id === 'number') {
    return {
      type: action.type,
      subject_id: payload.subject_id,
      step_token: typeof payload.step_token === 'string' ? payload.step_token : '',
      reason: typeof payload.reason === 'string' && payload.reason !== '' ? payload.reason : null,
    };
  }
  if (typeof payload.value === 'string') return decodeSpCallback(payload.value);
  if (typeof payload.arg === 'string') return decodeSpCallback(`${action.type}:${payload.arg}`);
  return null;
}

export async function buildSpKeyboard(
  buttons: readonly NotifyButton[],
  input: Pick<RenderSpInput, 'actions' | 'ttlS' | 'locale' | 'owner' | 'translator'>,
): Promise<{ keyboard: InlineKeyboard | undefined; links: string[] }> {
  const keyboard = new InlineKeyboard();
  const links: string[] = [];
  let count = 0;
  let inRow = 0;
  for (const button of buttons) {
    const label = buttonLabel(button.text);
    if (button.url) {
      if (isPublicHttpUrl(button.url)) {
        if (inRow > 0) keyboard.row();
        keyboard.url(label, button.url).row();
        inRow = 0;
        count++;
      } else {
        links.push(input.translator.t(input.locale, 'sp.link_text', { url: escapeHtml(button.url) }));
      }
      continue;
    }
    if (!button.callback) continue;
    const decoded = decodeSpCallback(button.callback);
    const separator = button.callback.indexOf(':');
    const stored: StoredAction = {
      type: decoded?.type ?? (separator === -1 ? button.callback : button.callback.slice(0, separator)),
      payload: decoded
        ? { subject_id: decoded.subject_id, step_token: decoded.step_token, reason: decoded.reason }
        : { value: button.callback, arg: separator === -1 ? null : button.callback.slice(separator + 1) },
      telegram_user_id: input.owner.telegramUserId,
      chat_id: input.owner.chatId,
      workspace_id: input.owner.workspaceId,
      user_id: input.owner.userId,
      locale: input.locale,
      created_at: Date.now(),
    };
    const id = await input.actions.put(stored, input.ttlS);
    const wide = decoded?.type === 'sp.rej_reason';
    if (wide && inRow > 0) {
      keyboard.row();
      inRow = 0;
    }
    keyboard.text(label, `${ACTION_CALLBACK_PREFIX}${id}`);
    inRow++;
    count++;
    if (wide || inRow >= 2) {
      keyboard.row();
      inRow = 0;
    }
  }
  return { keyboard: count > 0 ? keyboard : undefined, links };
}

export async function renderSp(input: RenderSpInput): Promise<RenderedSp> {
  const template = input.registry.notification(input.template);
  if (!template) throw new UnknownTemplateError(input.template);
  const params = input.spActions ? { ...input.params, actions: input.spActions } : input.params;
  const rendered = template(params, { locale: input.locale, t: input.translator.forLocale(input.locale), escape: escapeHtml });
  const { keyboard, links } = await buildSpKeyboard(rendered.buttons ?? [], input);
  const html = toTelegramHtml(links.length > 0 ? `${rendered.html}\n\n${links.join('\n')}` : rendered.html);
  return { html, keyboard };
}
