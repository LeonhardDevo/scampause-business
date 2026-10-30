import { currencyExponent } from '../money.js';
import { dominantCurrency, findAmounts, pickAmount, type AmountCandidate } from './amount.js';
import { accountKey, choosePayee, detectBankDetails, findAccountHolder, findBankName, type AccountCandidate, type BicCandidate } from './bank.js';
import { findDates, pickDates, type DateCandidate } from './dates.js';
import {
  domainFromUrl,
  findEmails,
  findInvoiceNumber,
  findPhones,
  findTaxIds,
  findUrls,
  findVendorName,
  numberSignSpans,
  registrableDomain,
  type Found,
} from './fields.js';
import { suggestKind } from './kind.js';
import { isPaymentQr, parseQrPayload } from './qr.js';
import { allMatches, maskSpans, normalizeSpaces, type Span } from './text.js';
import type {
  ExtractedFields,
  ExtractionDocument,
  ExtractionInput,
  ExtractionStatus,
  Field,
  FieldSource,
  PayeeFields,
  QrPayeeFields,
  QrRecord,
} from './types.js';

export const DEFAULT_MAX_TEXT_CHARS = 200_000;

const COUNTRY_CURRENCY: Readonly<Record<string, string>> = Object.freeze({
  US: 'USD', RU: 'RUB', GB: 'GBP', KZ: 'KZT', CH: 'CHF', JP: 'JPY', BY: 'BYN', UA: 'UAH', CA: 'CAD', AU: 'AUD',
  DE: 'EUR', FR: 'EUR', ES: 'EUR', IT: 'EUR', NL: 'EUR', BE: 'EUR', AT: 'EUR', IE: 'EUR', PT: 'EUR', FI: 'EUR', GR: 'EUR',
  LT: 'EUR', LV: 'EUR', EE: 'EUR', SK: 'EUR', SI: 'EUR', LU: 'EUR', MT: 'EUR', CY: 'EUR', HR: 'EUR',
});

export function currencyForCountry(country: string): string {
  return COUNTRY_CURRENCY[country.toUpperCase()] ?? 'USD';
}

export interface ExtractOutcome {
  fields: ExtractedFields;
  warnings: string[];
  text: string;
  pages: number;
  ocrUsed: boolean;
  status: ExtractionStatus;
}

interface PreparedDocument {
  doc: ExtractionDocument;
  text: string;
  offsets: Array<{ start: number; page: number; ocr: boolean }>;
}

interface DocumentFindings {
  prepared: PreparedDocument;
  bank: { candidates: AccountCandidate[]; bics: BicCandidate[] };
  invoice: Found<string> | null;
  dates: DateCandidate[];
  taxVendor: Found<string> | null;
  amounts: AmountCandidate[];
  dominant: string | null;
  emails: Found<string>[];
  urls: Found<string>[];
  phones: Found<string>[];
  vendor: Found<string> | null;
}

function prepare(doc: ExtractionDocument): PreparedDocument {
  const offsets: PreparedDocument['offsets'] = [];
  let text = '';
  for (const page of doc.pages) {
    if (text !== '') text += '\n\n';
    offsets.push({ start: text.length, page: page.page, ocr: page.ocr_used });
    text += normalizeSpaces(page.text);
  }
  return { doc, text, offsets };
}

function locate(prepared: PreparedDocument, index: number): { page: number | null; source: FieldSource } {
  let current: PreparedDocument['offsets'][number] | undefined;
  for (const offset of prepared.offsets) {
    if (offset.start <= index) current = offset;
    else break;
  }
  return { page: current?.page ?? null, source: current?.ocr ? 'ocr' : 'text' };
}

function fieldAt<T>(prepared: PreparedDocument, index: number, value: T, confidence: number): Field<T> {
  const where = locate(prepared, index);
  return { value, confidence, source: where.source, page: where.page };
}

const NOISE_RES = [
  /\d+(?:[.,]\d+)?\s?%/g,
  /(?<!\d)\d{1,2}:\d{2}(?::\d{2})?(?!\d)/g,
  /(?<![\d.,])\d{9,}(?![.,]\d{1,2}(?!\d))/g,
  /(?<![\p{L}])(?:КПП|ОГРН|ОКПО|ОКВЭД|ZIP|P\.?O\.?\s*Box)[\s:№]*\d+/giu,
  /\+\d[\d\s()-]{6,}\d/g,
];

function analyse(prepared: PreparedDocument, input: ExtractionInput, fallbackCurrency: string): DocumentFindings {
  const { text } = prepared;
  const bank = detectBankDetails(text);
  const tax = findTaxIds(text);
  const invoice = findInvoiceNumber(text);
  const dates = findDates(text, input.country);
  const phones = findPhones(text, input.country, [...bank.spans, ...tax.all.map((item) => item.span)]);
  const noise: Span[] = NOISE_RES.flatMap((re) => allMatches(re, text).map((match) => ({ start: match.index, end: match.index + match[0].length })));
  const masked = maskSpans(text, [
    ...bank.spans,
    ...tax.all.map((item) => item.span),
    ...(invoice ? [invoice.span] : []),
    ...numberSignSpans(text),
    ...dates.map((date) => date.span),
    ...phones.map((phone) => phone.span),
    ...noise,
  ]);
  const dominant = dominantCurrency(masked);
  return {
    prepared,
    bank,
    invoice,
    dates,
    taxVendor: tax.vendor,
    amounts: findAmounts(masked, dominant ?? fallbackCurrency),
    dominant,
    emails: findEmails(text),
    urls: findUrls(text),
    phones,
    vendor: findVendorName(text, null),
  };
}

function toPayee(findings: DocumentFindings, candidate: AccountCandidate, warnings: string[]): PayeeFields {
  const prepared = findings.prepared;
  const at = candidate.span.start;
  const confidence = candidate.valid ? 90 : 50;
  const payee: PayeeFields = {
    scheme: fieldAt(prepared, at, candidate.scheme, confidence),
    account_number: fieldAt(prepared, at, candidate.account, confidence),
    valid_checksum: candidate.valid,
  };
  if (candidate.routing) payee.routing_code = fieldAt(prepared, at, candidate.routing, candidate.valid ? 85 : 50);
  if (candidate.correspondent) payee.correspondent_account = fieldAt(prepared, at, candidate.correspondent, 80);
  const bic = findings.bank.bics.find((item) => item.valid) ?? null;
  let country = candidate.country;
  if (bic) {
    const bicCountry = bic.value.slice(4, 6);
    const consistent = candidate.scheme !== 'iban' || bicCountry === candidate.country;
    if (!consistent && !warnings.includes('BIC_COUNTRY_MISMATCH')) warnings.push('BIC_COUNTRY_MISMATCH');
    payee.bic = fieldAt(prepared, bic.span.start, bic.value, consistent ? 85 : 40);
    country ??= bicCountry;
  }
  if (country) payee.bank_country = fieldAt(prepared, at, country, candidate.scheme === 'iban' ? 95 : 80);
  const holder = findAccountHolder(prepared.text);
  if (holder) payee.account_holder = fieldAt(prepared, at, holder, 70);
  const bankName = findBankName(prepared.text);
  if (bankName) payee.bank_name = fieldAt(prepared, at, bankName, 65);
  if (!candidate.valid && !warnings.includes('INVALID_ACCOUNT_CHECKSUM')) warnings.push('INVALID_ACCOUNT_CHECKSUM');
  return payee;
}

function qrAsPayee(qr: QrPayeeFields): PayeeFields {
  const payee: PayeeFields = { scheme: qr.scheme, account_number: qr.account_number, valid_checksum: qr.valid_checksum };
  for (const key of ['routing_code', 'bic', 'account_holder', 'bank_name', 'bank_country', 'correspondent_account'] as const) {
    const value = qr[key];
    if (value) payee[key] = value;
  }
  return payee;
}

function unique(values: Iterable<string>): string[] {
  return [...new Set(values)];
}

export function extractFields(input: ExtractionInput, maxTextChars = DEFAULT_MAX_TEXT_CHARS): ExtractOutcome {
  const warnings: string[] = [];
  const ordered = [...input.documents].sort((a, b) => Number(a.role === 'body') - Number(b.role === 'body'));
  const prepared = ordered.map(prepare);
  const fallbackCurrency = input.vendor_currency ?? input.default_currency ?? currencyForCountry(input.country);
  const findings = prepared.map((doc) => analyse(doc, input, fallbackCurrency));
  const fields: ExtractedFields = { emails: [], domains: [], urls: [], phones_e164: [], qr: [], kind_suggestion: 'other' };

  const qrRecords: QrRecord[] = [];
  let qrPayee: QrPayeeFields | null = null;
  let qrCurrency: string | null = null;
  for (const item of input.qr_payloads) {
    const parsed = parseQrPayload(item.payload, item.page);
    qrRecords.push(parsed.record);
    if (parsed.record.format === 'unknown' && !warnings.includes('QR_UNKNOWN_FORMAT')) warnings.push('QR_UNKNOWN_FORMAT');
    if (parsed.payee && !qrPayee) {
      qrPayee = parsed.payee;
      qrCurrency = parsed.currency;
    }
  }
  fields.qr = qrRecords;
  if (qrPayee) fields.qr_payee = qrPayee;

  if (input.sender) fields.sender = input.sender;

  const allCandidates = findings.flatMap((finding) => finding.bank.candidates);
  const distinct = new Set(allCandidates.map(accountKey));
  const firstWithAccount = findings.find((finding) => finding.bank.candidates.length > 0);
  if (firstWithAccount) {
    const { chosen } = choosePayee(firstWithAccount.prepared.text, firstWithAccount.bank.candidates);
    if (chosen) fields.payee = toPayee(firstWithAccount, chosen, warnings);
  } else if (qrPayee) {
    fields.payee = qrAsPayee(qrPayee);
  }
  if (distinct.size > 1) warnings.push('MULTIPLE_ACCOUNTS_FOUND');

  const qrAmount = qrPayee?.amount_minor;
  if (qrAmount) {
    fields.amount_minor = qrAmount;
    fields.currency = { value: qrCurrency ?? 'RUB', confidence: 95, source: 'qr', page: qrAmount.page ?? null };
  } else {
    const withAmount = findings.find((finding) => finding.amounts.length > 0);
    const best = withAmount ? pickAmount(withAmount.amounts) : null;
    if (withAmount && best) {
      let currency: Field<string>;
      if (best.currency) currency = fieldAt(withAmount.prepared, best.span.start, best.currency, 90);
      else if (withAmount.dominant) currency = fieldAt(withAmount.prepared, best.span.start, withAmount.dominant, 60);
      else if (input.vendor_currency) currency = fieldAt(withAmount.prepared, best.span.start, input.vendor_currency, 50);
      else currency = fieldAt(withAmount.prepared, best.span.start, input.default_currency ?? currencyForCountry(input.country), 30);
      const exponentFix = currencyExponent(currency.value) === currencyExponent(best.currency ?? withAmount.dominant ?? fallbackCurrency);
      if (exponentFix && best.minor <= BigInt(Number.MAX_SAFE_INTEGER)) {
        fields.amount_minor = fieldAt(withAmount.prepared, best.span.start, Number(best.minor), best.confidence);
        fields.currency = currency;
        if (best.confidence < 60) warnings.push('LOW_CONFIDENCE_AMOUNT');
      }
    }
  }

  const withInvoice = findings.find((finding) => finding.invoice);
  if (withInvoice?.invoice) fields.invoice_number = fieldAt(withInvoice.prepared, withInvoice.invoice.span.start, withInvoice.invoice.value, withInvoice.invoice.confidence);

  for (const finding of findings) {
    if (finding.dates.length === 0) continue;
    const pick = pickDates(finding.prepared.text, finding.dates);
    const confidenceOf = (date: DateCandidate, base: number): number => (date.ambiguous ? 40 : base);
    if (pick.due && !fields.due_date) {
      fields.due_date = fieldAt(finding.prepared, pick.due.span.start, pick.due.value, confidenceOf(pick.due, 85));
      if (pick.due.ambiguous && !warnings.includes('AMBIGUOUS_DATE')) warnings.push('AMBIGUOUS_DATE');
    }
    if (pick.invoice && !fields.invoice_date) {
      fields.invoice_date = fieldAt(finding.prepared, pick.invoice.span.start, pick.invoice.value, confidenceOf(pick.invoice, pick.invoiceFromKeyword ? 85 : 50));
      if (pick.invoice.ambiguous && !warnings.includes('AMBIGUOUS_DATE')) warnings.push('AMBIGUOUS_DATE');
    }
  }

  const withTax = findings.find((finding) => finding.taxVendor);
  if (withTax?.taxVendor) fields.vendor_tax_id = fieldAt(withTax.prepared, withTax.taxVendor.span.start, withTax.taxVendor.value, withTax.taxVendor.confidence);

  const labelled = findings.find((finding) => finding.vendor && finding.vendor.confidence >= 60);
  if (labelled?.vendor) {
    fields.vendor_name = fieldAt(labelled.prepared, labelled.vendor.span.start, labelled.vendor.value, labelled.vendor.confidence);
  } else if (input.sender?.from_name) {
    fields.vendor_name = { value: input.sender.from_name.slice(0, 120), confidence: 60, source: 'eml_header', page: null };
  } else if (qrPayee?.account_holder) {
    fields.vendor_name = { ...qrPayee.account_holder, confidence: 80 };
  } else {
    const first = findings.find((finding) => finding.vendor);
    if (first?.vendor) fields.vendor_name = fieldAt(first.prepared, first.vendor.span.start, first.vendor.value, Math.min(first.vendor.confidence, 40));
  }

  const senderEmails = [input.sender?.from_email, input.sender?.reply_to, input.sender?.return_path].filter((value): value is string => Boolean(value));
  fields.emails = unique(findings.flatMap((finding) => finding.emails.map((item) => item.value))).slice(0, 50);
  fields.urls = unique(findings.flatMap((finding) => finding.urls.map((item) => item.value))).slice(0, 50);
  const qrUrls = qrRecords.filter((record) => record.format === 'url').map((record) => record.fields.url ?? '');
  fields.domains = unique(
    [...senderEmails, ...fields.emails].map(registrableDomain).concat([...fields.urls, ...qrUrls].map(domainFromUrl)).filter((value): value is string => Boolean(value)),
  ).slice(0, 50);
  fields.phones_e164 = unique(findings.flatMap((finding) => finding.phones.map((item) => item.value))).slice(0, 20);

  const combined = prepared.map((doc) => doc.text).filter((text) => text.trim() !== '').join('\n\n');
  const kindText = `${input.sender?.subject ?? ''}\n${combined}`;
  fields.kind_suggestion = suggestKind({
    text: kindText,
    hasPaymentQr: qrRecords.some(isPaymentQr),
    hasInvoiceNumber: Boolean(fields.invoice_number),
    hasAmount: Boolean(fields.amount_minor),
  });

  const pages = prepared.reduce((sum, doc) => sum + doc.doc.pages.length, 0);
  const ocrUsed = prepared.some((doc) => doc.doc.pages.some((page) => page.ocr_used));
  const hasText = combined.trim() !== '' || qrRecords.length > 0;
  if (!hasText) warnings.push('NO_TEXT');
  const status: ExtractionStatus = !hasText ? 'failed' : fields.amount_minor && fields.payee ? 'succeeded' : 'partial';
  if (combined.length > maxTextChars) warnings.push('TEXT_TRUNCATED');
  return { fields, warnings, text: combined.slice(0, maxTextChars), pages, ocrUsed, status };
}
