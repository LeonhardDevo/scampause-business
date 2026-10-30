import { describe, expect, it } from 'vitest';
import { parseAmountDigits } from '../../src/domain/extract/amount.js';
import { findDates } from '../../src/domain/extract/dates.js';
import { extractFields, type ExtractOutcome } from '../../src/domain/extract/extractFields.js';
import { parseQrPayload } from '../../src/domain/extract/qr.js';
import { EXTRACTOR_VERSION, type ExtractionInput, type SenderFields } from '../../src/domain/extract/types.js';
import { isValidAba, isValidIban, isValidInn, isValidRuAccount, isValidRuCorrespondent } from '../../src/domain/extract/validators.js';
import { BEC_EMAIL, EPC_PAYLOAD, RU_1C_INVOICE, ST00012_PAYLOAD, US_INVOICE } from './fixtures.js';

function run(text: string, country = 'US', extra: Partial<ExtractionInput> = {}): ExtractOutcome {
  return extractFields({
    documents: [{ file_id: 1, role: 'attachment', pages: [{ page: 1, text, ocr_used: false }] }],
    qr_payloads: [],
    sender: null,
    country,
    locale: 'en',
    ...extra,
  });
}

describe('validators', () => {
  it.each(['DE89370400440532013000', 'LT093250024720018839', 'ES7921000813610123456789', 'GB82BARC20035544332211', 'DE89 3704 0044 0532 0130 00'])(
    'accepts IBAN %s',
    (iban) => expect(isValidIban(iban)).toBe(true),
  );

  it.each(['DE89370400440532013001', 'XX89370400440532013000', 'DE8937040044053201300'])('rejects IBAN %s', (iban) => expect(isValidIban(iban)).toBe(false));

  it('checks ABA routing numbers', () => {
    expect(['021000021', '121000248', '026009593'].map(isValidAba)).toEqual([true, true, true]);
    expect(isValidAba('021000022')).toBe(false);
    expect(isValidAba('000000000')).toBe(false);
  });

  it('checks Russian account keys and INN checksums', () => {
    expect(isValidRuAccount('044525225', '40702810938000012345')).toBe(true);
    expect(isValidRuAccount('044525225', '40702810938000012346')).toBe(false);
    expect(isValidRuCorrespondent('044525225', '30101810400000000225')).toBe(true);
    expect(isValidInn('7707083893')).toBe(true);
    expect(isValidInn('500100732259')).toBe(true);
    expect(isValidInn('7707083894')).toBe(false);
  });
});

describe('amount digits', () => {
  it.each([
    ['18400.00', 2, 1840000n],
    ['18 400,00', 2, 1840000n],
    ['18\u00A0400,00', 2, 1840000n],
    ['18,400.00', 2, 1840000n],
    ['1.234,56', 2, 123456n],
    ['185000,00', 2, 18500000n],
    ['1,234', 2, 123400n],
    ['12,5', 2, 1250n],
    ['4,000', 0, 4000n],
  ])('parses %s with exponent %i', (raw, exponent, expected) => {
    expect(parseAmountDigits(raw, exponent)).toBe(expected);
  });
});

describe('extractFields — fixture texts', () => {
  it('reads a 1C «Счёт на оплату»', () => {
    const { fields, status, warnings } = run(RU_1C_INVOICE, 'RU');
    expect(status).toBe('succeeded');
    expect(warnings).toEqual([]);
    expect(fields.amount_minor).toMatchObject({ value: 24830000, source: 'text', page: 1 });
    expect(fields.currency?.value).toBe('RUB');
    expect(fields.payee).toMatchObject({
      scheme: { value: 'ru_account' },
      account_number: { value: '40702810938000012345' },
      routing_code: { value: '044525225' },
      correspondent_account: { value: '30101810400000000225' },
      bank_country: { value: 'RU' },
      valid_checksum: true,
    });
    expect(fields.payee?.account_holder?.value).toBe('ООО «Стройматериалы Север»');
    expect(fields.payee?.bank_name?.value).toBe('ПАО СБЕРБАНК Г. МОСКВА');
    expect(fields.vendor_name?.value).toBe('ООО «Стройматериалы Север»');
    expect(fields.vendor_tax_id?.value).toBe('7707083893');
    expect(fields.invoice_number?.value).toBe('512');
    expect(fields.invoice_date?.value).toBe('2026-10-14');
    expect(fields.due_date?.value).toBe('2026-10-21');
    expect(fields.kind_suggestion).toBe('invoice');
  });

  it('reads a US invoice with ACH details', () => {
    const { fields, status } = run(US_INVOICE, 'US');
    expect(status).toBe('succeeded');
    expect(fields.amount_minor?.value).toBe(721500);
    expect(fields.currency?.value).toBe('USD');
    expect(fields.payee).toMatchObject({ scheme: { value: 'us_ach' }, account_number: { value: '000483924417' }, routing_code: { value: '021000021' }, valid_checksum: true });
    expect(fields.payee?.bank_name?.value).toBe('JPMorgan Chase Bank, N.A.');
    expect(fields.payee?.account_holder?.value).toBe('Blue Ridge Packaging Company, Inc.');
    expect(fields.invoice_number?.value).toBe('INV-88390');
    expect(fields.invoice_date?.value).toBe('2026-10-20');
    expect(fields.due_date?.value).toBe('2026-11-19');
    expect(fields.phones_e164).toEqual(['+18285550142']);
    expect(fields.vendor_name?.value).toBe('Blue Ridge Packaging Co.');
  });

  it('reads a bank-change e-mail', () => {
    const { fields } = run(BEC_EMAIL, 'US');
    expect(fields.payee).toMatchObject({ scheme: { value: 'iban' }, account_number: { value: 'LT093250024720018839' }, bic: { value: 'REVOLT21' }, bank_country: { value: 'LT' }, valid_checksum: true });
    expect(fields.amount_minor?.value).toBe(1840000);
    expect(fields.kind_suggestion).toBe('bank_details_change');
    expect(fields.phones_e164).toEqual(['+18285550199']);
  });

  const amountCases: Array<[string, string, number, string]> = [
    ['Total due: EUR18400.00', 'DE', 1840000, 'EUR'],
    ['Итого: 185000,00', 'RU', 18500000, 'RUB'],
    ['Итого к оплате: 18 400,00 ₽', 'RU', 1840000, 'RUB'],
    ['Amount due: $18,400.00', 'US', 1840000, 'USD'],
    ['Total 18400.00', 'US', 1840000, 'USD'],
    ['Gesamtbetrag\nTotal: 1.234,56 EUR', 'DE', 123456, 'EUR'],
    ['Subtotal $1,000.00\nVAT $200.00\nTotal $1,200.00', 'US', 120000, 'USD'],
    ['Total ¥4,000', 'JP', 4000, 'JPY'],
    ['ИТОГО   К ОПЛАТЕ :  96 400,00 руб.', 'RU', 9640000, 'RUB'],
    ['Сумма к оплате 60 000,00 рублей', 'RU', 6000000, 'RUB'],
  ];
  it.each(amountCases)('finds the total in %j', (text, country, minor, currency) => {
    const { fields } = run(text, country);
    expect(fields.amount_minor?.value).toBe(minor);
    expect(fields.currency?.value).toBe(currency);
  });

  it('never parses dates, phones or account numbers as amounts', () => {
    const { fields } = run('Date: 2026-10-22\nPhone +1 828 555 0142\nAccount number 000483924417\nPO Box 28801', 'US');
    expect(fields.amount_minor).toBeUndefined();
  });

  it('falls back to the workspace currency with low confidence', () => {
    const { fields, warnings } = run('Total 1 250,00', 'KZ');
    expect(fields.currency).toMatchObject({ value: 'KZT', confidence: 30 });
    expect(fields.amount_minor?.value).toBe(125000);
    expect(warnings).not.toContain('LOW_CONFIDENCE_AMOUNT');
  });

  it('flags low-confidence amounts', () => {
    const { warnings, fields } = run('Payment 1,250.50 for services', 'US');
    expect(fields.amount_minor?.value).toBe(125050);
    expect(warnings).toContain('LOW_CONFIDENCE_AMOUNT');
  });

  it('reads UK sort code + account', () => {
    const { fields } = run('Beneficiary: Harbor Freight Ltd\nSort code: 20-00-00\nAccount number: 55779911\nTotal £1,000.00', 'GB');
    expect(fields.payee).toMatchObject({ scheme: { value: 'uk_sort_code' }, routing_code: { value: '200000' }, account_number: { value: '55779911' }, bank_country: { value: 'GB' } });
  });

  it('keeps a labelled IBAN with a bad checksum and warns', () => {
    const { fields, warnings } = run('IBAN: DE89 3704 0044 0532 0130 01\nTotal: €100.00', 'DE');
    expect(fields.payee?.valid_checksum).toBe(false);
    expect(warnings).toContain('INVALID_ACCOUNT_CHECKSUM');
  });

  it('warns about multiple payee accounts and keeps the one near payment words', () => {
    const text = 'Old account: GB82 BARC 2003 5544 3322 11 (closed)\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\nPlease pay to the new account IBAN LT09 3250 0247 2001 8839\nTotal €2,000.00';
    const { fields, warnings } = run(text, 'DE');
    expect(warnings).toContain('MULTIPLE_ACCOUNTS_FOUND');
    expect(fields.payee?.account_number.value).toBe('LT093250024720018839');
  });

  it('detects US routing with a broken checksum', () => {
    const { fields } = run('Routing number: 021000022\nAccount number: 12345678\nTotal $10.00', 'US');
    expect(fields.payee).toMatchObject({ scheme: { value: 'us_ach' }, valid_checksum: false });
  });

  it('marks slash dates as ambiguous outside the US', () => {
    const { fields, warnings } = run('Invoice date: 03/04/2026\nTotal €10.00', 'DE');
    expect(fields.invoice_date).toMatchObject({ value: '2026-04-03', confidence: 40 });
    expect(warnings).toContain('AMBIGUOUS_DATE');
  });

  it('reads US slash dates month first', () => {
    const { fields } = run('Due date: 11/19/2026\nTotal $10.00', 'US');
    expect(fields.due_date).toMatchObject({ value: '2026-11-19', confidence: 85 });
  });

  it('reads Russian genitive month dates', () => {
    const { fields } = run('Счёт № А-17 от 2 октября 2026 г.\nСрок оплаты: 30 октября 2026 г.\nИтого: 1 000,00', 'RU');
    expect(fields.invoice_number?.value).toBe('А-17');
    expect(fields.invoice_date?.value).toBe('2026-10-02');
    expect(fields.due_date?.value).toBe('2026-10-30');
  });

  it.each([
    ['Please update my direct deposit to the new account below', 'payroll_change'],
    ['Прошу изменить зарплатный счёт, новый счёт ниже', 'payroll_change'],
    ['Urgent wire transfer needed today, the CEO asked', 'urgent_wire'],
    ['Сообщаем, что у нас новые реквизиты для оплаты', 'bank_details_change'],
    ['Hello, just checking in', 'other'],
  ])('suggests the kind for %j', (text, kind) => {
    expect(run(text).fields.kind_suggestion).toBe(kind);
  });

  it('extracts e-mails, links, domains, phones and tax ids', () => {
    const text = 'Contact billing@blueridge-pack.com or https://portal.blueridge-pack.com/pay.\nwww.example.co.uk\nEIN: 56-1234567\nТел.: +7 495 555-02-17';
    const { fields } = run(text, 'US');
    expect(fields.emails).toEqual(['billing@blueridge-pack.com']);
    expect(fields.urls).toEqual(['https://portal.blueridge-pack.com/pay', 'http://www.example.co.uk']);
    expect(fields.domains).toEqual(['blueridge-pack.com', 'example.co.uk']);
    expect(fields.vendor_tax_id?.value).toBe('56-1234567');
    expect(fields.phones_e164).toContain('+74955550217');
  });

  it('prefers EPC QR amount and keeps the text account as payee', () => {
    const { fields } = run('Invoice HL-7790\nRouting number: 031101279\nAccount number: 5501206604\nTotal: $2,340.00', 'US', { qr_payloads: [{ page: 1, payload: EPC_PAYLOAD }] });
    expect(fields.amount_minor).toMatchObject({ value: 234000, source: 'qr', confidence: 95 });
    expect(fields.currency?.value).toBe('EUR');
    expect(fields.payee?.account_number.value).toBe('5501206604');
    expect(fields.qr_payee?.account_number.value).toBe('DE89370400440532013000');
    expect(fields.qr[0]?.format).toBe('epc');
    expect(fields.kind_suggestion).toBe('invoice');
  });

  it('uses the ST00012 QR as payee when the text has none', () => {
    const { fields } = run('Оплата аренды склада', 'RU', { qr_payloads: [{ page: 1, payload: ST00012_PAYLOAD }] });
    expect(fields.payee).toMatchObject({ scheme: { value: 'ru_account', source: 'qr' }, account_number: { value: '40702810938000012345' }, valid_checksum: true });
    expect(fields.qr_payee?.payee_tax_id?.value).toBe('7707083893');
    expect(fields.amount_minor?.value).toBe(18500000);
    expect(fields.kind_suggestion).toBe('qr_payment');
  });

  it('warns about unknown QR formats', () => {
    expect(run('text', 'US', { qr_payloads: [{ page: 1, payload: 'hello world' }] }).warnings).toContain('QR_UNKNOWN_FORMAT');
    expect(parseQrPayload('https://pay.example.com/i/1', null).record.format).toBe('url');
  });

  it('fails when there is no text at all', () => {
    const outcome = run('   ', 'US');
    expect(outcome.status).toBe('failed');
    expect(outcome.warnings).toContain('NO_TEXT');
  });

  it('is partial when the payee is missing', () => {
    expect(run('Total due $5.00', 'US').status).toBe('partial');
  });

  it('marks OCR fields and keeps page numbers', () => {
    const outcome = extractFields({
      documents: [{ file_id: 9, role: 'attachment', pages: [{ page: 1, text: 'Scanned cover', ocr_used: true }, { page: 2, text: 'Total due $70.00', ocr_used: true }] }],
      qr_payloads: [],
      sender: null,
      country: 'US',
      locale: 'en',
    });
    expect(outcome.fields.amount_minor).toMatchObject({ value: 7000, source: 'ocr', page: 2 });
    expect(outcome.ocrUsed).toBe(true);
    expect(outcome.pages).toBe(2);
  });

  it('prefers attachment text over the e-mail body and keeps sender facts', () => {
    const sender: SenderFields = {
      from_name: 'Karen Whitfield',
      from_email: 'billing@blueridge-pack.com',
      reply_to: 'karen.whitfield.ar@gmail.com',
      return_path: 'bounce@mailer.blueridge-pack.com',
      to: ['maria@northwindroasters.com'],
      subject: 'Updated bank details',
      sent_at: '2026-10-20T10:15:00.000Z',
      message_id: '<msg-001@blueridge-pack.com>',
      auth: { spf: 'fail', dkim: 'none', dmarc: 'fail' },
      received_hops: 2,
    };
    const outcome = extractFields({
      documents: [
        { file_id: 1, role: 'body', pages: [{ page: 1, text: 'See attached. Total $1.00', ocr_used: false }] },
        { file_id: 2, role: 'attachment', pages: [{ page: 1, text: US_INVOICE, ocr_used: false }] },
      ],
      qr_payloads: [],
      sender,
      country: 'US',
      locale: 'en',
    });
    expect(outcome.fields.amount_minor?.value).toBe(721500);
    expect(outcome.fields.sender).toEqual(sender);
    expect(outcome.fields.domains).toEqual(expect.arrayContaining(['blueridge-pack.com', 'gmail.com']));
    expect(outcome.fields.kind_suggestion).toBe('bank_details_change');
  });

  it('truncates very long text', () => {
    const outcome = extractFields({ documents: [{ file_id: 1, role: 'attachment', pages: [{ page: 1, text: 'a'.repeat(5000), ocr_used: false }] }], qr_payloads: [], sender: null, country: 'US', locale: 'en' }, 1000);
    expect(outcome.text).toHaveLength(1000);
    expect(outcome.warnings).toContain('TEXT_TRUNCATED');
  });

  it('exposes the extractor version', () => {
    expect(EXTRACTOR_VERSION).toBe('worker-extract@1.0.0');
  });
});

describe('dates', () => {
  it('finds every supported format', () => {
    const text = '22.10.2026 2026-10-23 Oct 24, 2026 25 October 2026 26 октября 2026 г. 10/27/2026';
    expect(findDates(text, 'US').map((date) => date.value)).toEqual(['2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27']);
  });

  it('rejects impossible dates', () => {
    expect(findDates('31.02.2026', 'RU')).toEqual([]);
  });
});
