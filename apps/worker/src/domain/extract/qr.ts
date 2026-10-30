import { parseAmountDigits } from './amount.js';
import type { Field, QrPayeeFields, QrRecord } from './types.js';
import { isValidBicFormat, isValidIban, isValidRuAccount, normalizeDigits, normalizeIban } from './validators.js';

const QR_CONFIDENCE = 95;

export interface ParsedQr {
  record: QrRecord;
  payee: QrPayeeFields | null;
  currency: string | null;
}

function field<T>(value: T, page: number | null, confidence = QR_CONFIDENCE): Field<T> {
  return { value, confidence, source: 'qr', page };
}

function parseSt00012(payload: string, page: number | null): ParsedQr {
  const parts = payload.split('|');
  const fields: Record<string, string> = {};
  for (const part of parts.slice(1)) {
    const index = part.indexOf('=');
    if (index <= 0) continue;
    const key = part.slice(0, index).trim();
    const value = part.slice(index + 1).trim();
    if (key !== '' && value !== '') fields[key] = value;
  }
  const record: QrRecord = { page, format: 'st00012', fields };
  const account = normalizeDigits(fields.PersonalAcc ?? '');
  if (account === '') return { record, payee: null, currency: 'RUB' };
  const bik = normalizeDigits(fields.BIC ?? '');
  const valid = bik.length === 9 && isValidRuAccount(bik, account);
  const payee: QrPayeeFields = {
    scheme: field('ru_account' as const, page),
    account_number: field(account, page),
    valid_checksum: valid,
    bank_country: field('RU', page),
  };
  if (bik !== '') payee.routing_code = field(bik, page);
  if (fields.Name) payee.account_holder = field(fields.Name.slice(0, 120), page);
  if (fields.BankName) payee.bank_name = field(fields.BankName.slice(0, 120), page);
  const corr = normalizeDigits(fields.CorrespAcc ?? '');
  if (corr !== '') payee.correspondent_account = field(corr, page);
  const inn = normalizeDigits(fields.PayeeINN ?? '');
  if (inn !== '') payee.payee_tax_id = field(inn, page);
  const sum = fields.Sum ? normalizeDigits(fields.Sum) : '';
  if (sum !== '' && sum.length <= 15) payee.amount_minor = field(Number(sum), page);
  return { record, payee, currency: 'RUB' };
}

function parseEpc(payload: string, page: number | null): ParsedQr {
  const lines = payload.split(/\r?\n/);
  const fields: Record<string, string> = {};
  const keys = ['service_tag', 'version', 'charset', 'identification', 'bic', 'name', 'iban', 'amount', 'purpose', 'reference', 'text', 'information'];
  keys.forEach((key, index) => {
    const value = (lines[index] ?? '').trim();
    if (value !== '') fields[key] = value;
  });
  const record: QrRecord = { page, format: 'epc', fields };
  const iban = normalizeIban(fields.iban ?? '');
  if (iban === '') return { record, payee: null, currency: 'EUR' };
  const payee: QrPayeeFields = {
    scheme: field('iban' as const, page),
    account_number: field(iban, page),
    valid_checksum: isValidIban(iban),
    bank_country: field(iban.slice(0, 2), page),
  };
  const bic = (fields.bic ?? '').toUpperCase();
  if (bic !== '' && isValidBicFormat(bic)) payee.bic = field(bic, page);
  if (fields.name) payee.account_holder = field(fields.name.slice(0, 120), page);
  const amount = /^([A-Z]{3})(\d{1,9}(?:\.\d{1,2})?)$/.exec(fields.amount ?? '');
  let currency: string | null = 'EUR';
  if (amount) {
    currency = amount[1] ?? 'EUR';
    const minor = parseAmountDigits(amount[2] ?? '', 2);
    if (minor !== null && minor > 0n) payee.amount_minor = field(Number(minor), page);
  }
  return { record, payee, currency };
}

function isUrl(payload: string): boolean {
  try {
    const url = new URL(payload.trim());
    return url.protocol === 'http:' || url.protocol === 'https:';
  } catch {
    return false;
  }
}

export function parseQrPayload(payload: string, page: number | null): ParsedQr {
  const trimmed = payload.replace(/^\uFEFF/, '').trim();
  if (/^ST0001[123]\|/.test(trimmed)) return parseSt00012(trimmed, page);
  if (/^BCD\r?\n00[12]\r?\n[1-8]?\r?\nSCT\r?\n/.test(trimmed)) return parseEpc(trimmed, page);
  if (isUrl(trimmed)) return { record: { page, format: 'url', fields: { url: trimmed.slice(0, 2000) } }, payee: null, currency: null };
  return { record: { page, format: 'unknown', fields: {} }, payee: null, currency: null };
}

export function isPaymentQr(record: QrRecord): boolean {
  return record.format === 'st00012' || record.format === 'epc';
}
