import { InlineKeyboard } from 'grammy';
import { escapeHtml, isPublicHttpUrl } from './format.js';
import type { BotContext } from './types.js';

export const INTEGRATIONS_PATH = '/settings/integrations';

export function settingsUrl(ctx: BotContext): string {
  return `${ctx.services.settings.webPublicUrl}${INTEGRATIONS_PATH}`;
}

export function isPrivateChat(ctx: BotContext): boolean {
  return ctx.chat?.type === 'private';
}

export function withSettingsLink(ctx: BotContext, html: string): { html: string; keyboard?: InlineKeyboard } {
  const url = settingsUrl(ctx);
  if (isPublicHttpUrl(url)) {
    return { html, keyboard: new InlineKeyboard().url(ctx.t('start.open_settings'), url) };
  }
  return { html: `${html}\n\n${ctx.t('unlinked.settings_url', { url: escapeHtml(url) })}` };
}

export async function replyHtml(ctx: BotContext, html: string, keyboard?: InlineKeyboard): Promise<void> {
  await ctx.reply(html, {
    parse_mode: 'HTML',
    link_preview_options: { is_disabled: true },
    ...(keyboard ? { reply_markup: keyboard } : {}),
  });
}

export async function replyUnlinked(ctx: BotContext): Promise<void> {
  const { html, keyboard } = withSettingsLink(ctx, ctx.t('unlinked.message'));
  await replyHtml(ctx, html, keyboard);
}
