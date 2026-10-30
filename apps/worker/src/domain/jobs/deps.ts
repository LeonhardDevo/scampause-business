import type { JobEnqueuer } from '../../queues/enqueuer.js';
import type { DomainWebApi } from '../client.js';
import type { DomainConfig } from '../config.js';
import type { ExtractedFields } from '../extract/types.js';
import type { DomainIntelDriver } from '../intel/types.js';
import type { DomainLocks } from '../locks.js';
import type { DomainMetrics } from '../metrics.js';
import type { ParsedFile, ParseFileInput } from '../parse/parseFile.js';

export interface IntakeFlowSpec {
  paymentRequestId: number;
  workspaceId: number;
  revision: number;
  domains: string[];
}

export interface IntakeFlowStarter {
  startIntakeFlow(spec: IntakeFlowSpec): Promise<void>;
}

export interface FileParserLike {
  parse(input: ParseFileInput): Promise<ParsedFile>;
}

export interface ExtractAssistRequest {
  workspace_id: number;
  ai_external_allowed: boolean;
  locale: 'ru' | 'en';
  text: string;
  missing: Array<'vendor_name' | 'amount' | 'invoice_number' | 'due_date'>;
  currency: string;
}

export interface ExtractAssistLike {
  assist(request: ExtractAssistRequest): Promise<Partial<Pick<ExtractedFields, 'vendor_name' | 'amount_minor' | 'currency' | 'invoice_number' | 'due_date'>>>;
}

export interface DomainJobServices {
  api: DomainWebApi;
  flows: IntakeFlowStarter;
  enqueuer: JobEnqueuer;
  locks: DomainLocks;
  parser: FileParserLike;
  intel: { live: DomainIntelDriver; fake: DomainIntelDriver };
  config: DomainConfig;
  metrics: DomainMetrics;
  assist: ExtractAssistLike | null;
  now: () => Date;
}
