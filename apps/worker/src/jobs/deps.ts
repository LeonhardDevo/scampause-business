import type { InlineKeyboardMarkup, Update } from 'grammy/types';
import type { AiApi } from '../clients/ai.js';
import type { WebApi } from '../clients/web.js';
import type { ChatPreferences } from '../db/telegramChats.js';
import type { WebhookEventStore } from '../db/webhookEvents.js';
import type { Translator } from '../i18n/index.js';
import type { Mailer } from '../mail/mailer.js';
import type { ActionStore, KeyValueCache } from '../bot/stores.js';
import type { BotRegistry } from '../bot/types.js';
import type { DomainJobServices } from '../domain/jobs/deps.js';

export interface TelegramSendOptions {
  replyToMessageId?: number | null;
}

export interface TelegramSender {
  sendMessage(chatId: number | string, html: string, replyMarkup?: InlineKeyboardMarkup, options?: TelegramSendOptions): Promise<{ message_id: number }>;
  editMessageText?(chatId: number | string, messageId: number, html: string, replyMarkup?: InlineKeyboardMarkup): Promise<void>;
}

export interface JobDeps {
  web: WebApi;
  ai: AiApi;
  mailer: Mailer;
  telegram: TelegramSender;
  sentMarkers: KeyValueCache;
  webhookEvents: WebhookEventStore;
  telegramChats: Pick<ChatPreferences, 'forget'>;
  bot: { handleUpdate(update: Update): Promise<void> };
  registry: BotRegistry;
  translator: Translator;
  actions: ActionStore;
  settings: {
    productSlug: string;
    mailFrom: string;
    notificationActionTtlS: number;
  };
  domain?: DomainJobServices;
}

export const SENT_MARKER_TTL_S = 7 * 24 * 3600;
