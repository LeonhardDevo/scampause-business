import type { DomainWebApi } from '../../../domain/client.js';

export type ScampauseBotApi = Pick<DomainWebApi, 'telegramCallback' | 'telegramWorkspace' | 'telegramPending' | 'telegramRequest'>;
