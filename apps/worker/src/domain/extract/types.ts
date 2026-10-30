export const EXTRACTOR_VERSION = 'worker-extract@1.0.0';

export type FieldSource = 'text' | 'ocr' | 'qr' | 'eml_header' | 'llm_assist';

export interface Field<T> {
  value: T;
  confidence: number;
  source: FieldSource;
  page?: number | null;
}

export type AuthResult = 'pass' | 'fail' | 'softfail' | 'neutral' | 'none' | 'temperror' | 'permerror';

export type BankScheme = 'iban' | 'us_ach' | 'ru_account' | 'uk_sort_code' | 'other';

export interface PayeeFields {
  scheme: Field<BankScheme>;
  account_number: Field<string>;
  routing_code?: Field<string>;
  bic?: Field<string>;
  account_holder?: Field<string>;
  bank_name?: Field<string>;
  bank_country?: Field<string>;
  correspondent_account?: Field<string>;
  valid_checksum: boolean;
}

export type QrPayeeFields = PayeeFields & { payee_tax_id?: Field<string>; amount_minor?: Field<number> };

export interface SenderFields {
  from_name: string | null;
  from_email: string | null;
  reply_to: string | null;
  return_path: string | null;
  to: string[];
  subject: string | null;
  sent_at: string | null;
  message_id: string | null;
  auth: { spf: AuthResult | null; dkim: AuthResult | null; dmarc: AuthResult | null };
  received_hops: number;
}

export type QrFormat = 'st00012' | 'epc' | 'url' | 'unknown';

export interface QrRecord {
  page: number | null;
  format: QrFormat;
  fields: Record<string, string>;
}

export type KindSuggestion = 'invoice' | 'bank_details_change' | 'urgent_wire' | 'payroll_change' | 'qr_payment' | 'other';

export interface ExtractedFields {
  vendor_name?: Field<string>;
  vendor_tax_id?: Field<string>;
  invoice_number?: Field<string>;
  invoice_date?: Field<string>;
  due_date?: Field<string>;
  amount_minor?: Field<number>;
  currency?: Field<string>;
  payee?: PayeeFields;
  qr_payee?: QrPayeeFields;
  sender?: SenderFields;
  emails: string[];
  domains: string[];
  urls: string[];
  phones_e164: string[];
  qr: QrRecord[];
  kind_suggestion: KindSuggestion;
}

export type ExtractionStatus = 'succeeded' | 'partial' | 'failed';

export interface ExtractionFileSummary {
  file_id: number;
  pages: number;
  ocr_used: boolean;
}

export interface ExtractionResult {
  workspace_id: number;
  payment_request_id: number;
  revision: number;
  status: ExtractionStatus;
  extractor_version: string;
  ocr_used: boolean;
  pages: number;
  text: string;
  fields: ExtractedFields;
  warnings: string[];
  error_code: string | null;
  duration_ms: number;
  llm_assisted: boolean;
  files: ExtractionFileSummary[];
}

export interface DocumentPage {
  page: number;
  text: string;
  ocr_used: boolean;
}

export type DocumentRole = 'attachment' | 'body';

export interface ExtractionDocument {
  file_id: number | null;
  role: DocumentRole;
  pages: DocumentPage[];
}

export interface ExtractionInput {
  documents: ExtractionDocument[];
  qr_payloads: Array<{ page: number | null; payload: string }>;
  sender: SenderFields | null;
  country: string;
  locale: 'ru' | 'en';
  default_currency?: string | null;
  vendor_currency?: string | null;
}
