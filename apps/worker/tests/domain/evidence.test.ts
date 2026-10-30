import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { csvCell, toCsv, toNdjson } from '../../src/domain/audit/csv.js';
import { evidencePacketSchema, renderEvidencePacket, renderJournalPdf } from '../../src/domain/audit/packet.js';
import { containsEmoji, findForbiddenCopy, hasForbiddenCopy } from '../../src/domain/copyGuard.js';
import { CanonicalJsonError, canonicalJson, chainHash, GENESIS_HASH, verifyChain, verifyEntries } from '../../src/domain/evidence/canonical.js';
import { HASHES, vectorEntries } from './fixtures.js';
import { LEXICON, matchPhrases, categoryPhrases } from '../../src/domain/lexicon.js';
import { formatMoney, parseMajorToMinor } from '../../src/domain/money.js';
import { ruPlural } from '../../src/domain/plural.js';

describe('canonical JSON and the evidence chain', () => {
  it('matches the shared canonicalisation vectors', () => {
    expect(canonicalJson({})).toBe('{}');
    expect(canonicalJson({ b: 'Ж\u2028', a: [], 9: 1, 10: 2 })).toBe('{"10":2,"9":1,"a":[],"b":"Ж\u2028"}');
    expect(canonicalJson({ a: [[3, 1], { z: 1, y: [2, 1] }] })).toBe('{"a":[[3,1],{"y":[2,1],"z":1}]}');
    expect(canonicalJson({ path: 'a/b', quote: '"' })).toBe('{"path":"a/b","quote":"\\""}');
    expect(() => canonicalJson({ amount: 1.5 })).toThrow(CanonicalJsonError);
  });

  it('reproduces the shared hash vectors', () => {
    expect(vectorEntries().map((entry) => entry.hash)).toEqual(HASHES);
  });

  it('verifies a chain and finds the first broken entry', () => {
    const entries = vectorEntries();
    expect(verifyChain(entries, GENESIS_HASH)).toEqual({ ok: true, checked: 3, first_broken_seq: null, head_hash: HASHES[2] });
    const tampered = entries.map((entry) => ({ ...entry }));
    tampered[1]!.material = tampered[1]!.material.replace('"high"', '"low"');
    expect(verifyChain(tampered)).toEqual({ ok: false, checked: 1, first_broken_seq: 2, head_hash: HASHES[0] });
    const reordered = [entries[0]!, entries[2]!];
    expect(verifyChain(reordered).first_broken_seq).toBe(3);
    const segment = entries.slice(1);
    expect(verifyChain(segment).ok).toBe(true);
    expect(verifyChain(segment, GENESIS_HASH).ok).toBe(false);
  });

  it('verifies the entries of one payment request even when other requests interleave in the ledger', () => {
    const entries = vectorEntries();
    const own = [entries[0]!, entries[2]!];
    expect(verifyEntries(own)).toEqual({ ok: true, checked: 2, first_broken_seq: null, head_hash: HASHES[2] });
    expect(verifyEntries(entries).ok).toBe(true);

    const tampered = own.map((entry) => ({ ...entry }));
    tampered[1]!.material = tampered[1]!.material.replace('"request.approved"', '"request.rejected"');
    expect(verifyEntries(tampered)).toMatchObject({ ok: false, first_broken_seq: 3, checked: 1 });

    const relinked = entries.map((entry) => ({ ...entry }));
    relinked[1]!.prev_hash = 'f'.repeat(64);
    relinked[1]!.hash = chainHash(relinked[1]!.prev_hash, relinked[1]!.material);
    expect(verifyEntries(relinked).first_broken_seq).toBe(2);

    const renumbered = [{ ...entries[0]!, seq: 5 }];
    expect(verifyEntries(renumbered).ok).toBe(false);
    expect(verifyEntries([entries[2]!, entries[0]!]).ok).toBe(false);
  });
});

describe('exports', () => {
  it('neutralises CSV injection and quotes every field', () => {
    expect(csvCell('=HYPERLINK("http://x","Click")')).toBe('"\'=HYPERLINK(""http://x"",""Click"")"');
    expect(csvCell('+1 828')).toBe('"\'+1 828"');
    expect(csvCell('-5')).toBe('"\'-5"');
    expect(csvCell('@SUM(A1)')).toBe('"\'@SUM(A1)"');
    expect(csvCell('\tx')).toBe('"\'\tx"');
    expect(csvCell(42)).toBe('"42"');
    expect(csvCell(null)).toBe('""');
    const csv = toCsv(['№', 'Событие'], [[1, 'approval.approved']]);
    expect([...csv.subarray(0, 3)]).toEqual([0xef, 0xbb, 0xbf]);
    expect(csv.subarray(3).toString('utf8')).toBe('"№","Событие"\r\n"1","approval.approved"\r\n');
  });

  it('writes NDJSON', () => {
    expect(toNdjson([{ a: 1 }, { b: 'x' }]).toString('utf8')).toBe('{"a":1}\n{"b":"x"}\n');
    expect(toNdjson([]).toString('utf8')).toBe('');
  });

  it('renders an evidence packet PDF with Cyrillic text', async () => {
    const entries = vectorEntries();
    const data = evidencePacketSchema.parse({
      workspace: { name: 'Кедр и Партнёры', timezone: 'Europe/Moscow' },
      locale: 'ru',
      generated_at: '2026-10-22T10:30:00.000000Z',
      request: {
        reference: 'SP-00042',
        title: 'Смена реквизитов ТеплоСервис',
        kind_label: 'Смена реквизитов',
        status_label: 'Отклонён',
        amount_formatted: '96 400,00 ₽',
        vendor_name: 'ООО «ТеплоСервис»',
        invoice_number: '512',
        due_date: '2026-10-30',
        payee_masked: 'Сбербанк · RU · •••• 6543',
        risk: { score: 100, level_label: 'Критический', signals: [{ title: 'Похожий домен', points: 40, explanation: 'teplo-servis.ru похож на teploservis.ru' }] },
      },
      verifications: [{ method_label: 'Обратный звонок', outcome_label: 'Отказ', performed_by: 'Ольга', checklist: [{ label: 'Последние 4 цифры', value: 'нет' }] }],
      approvals: [],
      entries: entries.map((entry, index) => ({ ...entry, event_label: `Событие ${index + 1}`, actor: 'Ольга', channel: 'web', occurred_at_local: '22.10.2026 13:15' })),
      disclaimer: 'ScamPause показывает сигналы риска и не гарантирует безопасность платежей.',
    });
    const chain = verifyChain(data.entries, GENESIS_HASH);
    const pdf = await renderEvidencePacket(data, chain);
    expect(pdf.subarray(0, 5).toString('latin1')).toBe('%PDF-');
    expect(pdf.byteLength).toBeGreaterThan(5000);
    const journal = await renderJournalPdf({ locale: 'en', workspace: 'Northwind', from: '2026-10-01', to: '2026-10-22', generatedAt: '2026-10-22T10:30:00.000Z', labels: {}, entries: [], chain });
    expect(journal.subarray(0, 5).toString('latin1')).toBe('%PDF-');
  });
});

describe('money and plurals', () => {
  it.each([
    ['en', 'USD', 400000, '$4,000.00'],
    ['ru', 'USD', 400000, '4\u00A0000,00\u00A0$'],
    ['ru', 'RUB', 18500000, '185\u00A0000,00\u00A0₽'],
    ['en', 'EUR', 1325000, '€13,250.00'],
    ['ru', 'EUR', 1325000, '13\u00A0250,00\u00A0€'],
    ['en', 'JPY', 4000, '¥4,000'],
    ['ru', 'JPY', 4000, '4\u00A0000\u00A0¥'],
    ['en', 'RUB', 249000, 'RUB 2,490.00'],
    ['en', 'GBP', 5, '£0.05'],
    ['ru', 'USD', 0, '0,00\u00A0$'],
    ['en', 'USD', 123456789, '$1,234,567.89'],
    ['ru', 'KZT', 100, '1,00\u00A0₸'],
  ] as const)('formats %s %s %i', (locale, currency, minor, expected) => {
    expect(formatMoney(minor, currency, locale)).toBe(expected);
  });

  it('parses major units without floats', () => {
    expect(parseMajorToMinor('4000', 'USD')).toBe(400000n);
    expect(parseMajorToMinor('4000.5', 'USD')).toBe(400050n);
    expect(parseMajorToMinor('4000.5', 'JPY')).toBeNull();
  });

  it('uses three Russian plural forms', () => {
    const forms = { one: 'счёт', few: 'счёта', many: 'счетов' };
    expect([1, 2, 5, 11, 21, 22, 25].map((count) => `${count} ${ruPlural(count, forms)}`)).toEqual(['1 счёт', '2 счёта', '5 счетов', '11 счетов', '21 счёт', '22 счёта', '25 счетов']);
  });
});

describe('copy guard and lexicon', () => {
  it('finds non-negated forbidden phrases only', () => {
    expect(hasForbiddenCopy('The payment is safe, go ahead.')).toBe(true);
    expect(hasForbiddenCopy('Можно смело платить.')).toBe(true);
    expect(hasForbiddenCopy('The score does not mean the payment is safe.')).toBe(false);
    expect(hasForbiddenCopy('Оценка не гарантирует безопасность платежа.')).toBe(false);
    expect(findForbiddenCopy('We guarantee 100% protection')).toHaveLength(2);
  });

  it('matches lexicon phrases with unicode boundaries and prefix stems', () => {
    expect(matchPhrases('Оплатите подарочными картами', categoryPhrases('UNUSUAL_PAYMENT_METHOD'))).toEqual(['подарочными картами']);
    expect(matchPhrases('pay with cryptocurrency', categoryPhrases('UNUSUAL_PAYMENT_METHOD'))).toEqual(['cryptocurrency']);
    expect(matchPhrases('This is urgently needed', categoryPhrases('URGENCY_LANGUAGE'))).toEqual(['urgently']);
    expect(matchPhrases('insurgent group', categoryPhrases('URGENCY_LANGUAGE'))).toEqual([]);
    expect(matchPhrases('Please don’t call me', categoryPhrases('CALLBACK_DISCOURAGED'))).toEqual(["don't call"]);
    expect(LEXICON.version).toBe('lex-2026.10.2');
    expect(LEXICON.web_only_signals).toEqual(expect.arrayContaining(LEXICON.bank_change_signals));
  });

  it('keeps every bot string, template, prompt and mock answer free of emoji and forbidden copy', async () => {
    const { RU, EN } = await import('../../src/bot/modules/scampause/messages.js');
    const strings: string[] = [];
    const collect = (node: unknown): void => {
      if (typeof node === 'string') strings.push(node);
      else if (node && typeof node === 'object') Object.values(node).forEach(collect);
    };
    collect(RU);
    collect(EN);
    const root = resolve(import.meta.dirname, '..', '..', 'src');
    for (const file of ['rag/prompts/domain.en.md', 'rag/prompts/domain.ru.md', 'rag/prompts/domain/mock_answers.en.json', 'rag/prompts/domain/mock_answers.ru.json', 'i18n/en.json', 'i18n/ru.json']) {
      strings.push(readFileSync(resolve(root, file), 'utf8'));
    }
    for (const file of readdirSync(resolve(root, 'domain/intel'))) strings.push(readFileSync(resolve(root, 'domain/intel', file), 'utf8'));
    expect(strings.length).toBeGreaterThan(100);
    for (const text of strings) {
      expect(containsEmoji(text), text.slice(0, 80)).toBe(false);
      expect(findForbiddenCopy(text), text.slice(0, 80)).toEqual([]);
    }
  });
});
