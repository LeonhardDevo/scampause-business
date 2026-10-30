import { parseAmountDigits } from '../../domain/extract/amount.js';
import type { ParsedSource } from '../answer/sources.js';
import { answerText } from '../domain/answers.js';
import { contentTerms, coverage } from '../text.js';
import { unwrapToolResult, type ComposeInput, type IntentRule, type IntentStep } from '../providers/mock.js';

const AMOUNT_RE = /(\$|usd|€|eur|₽|руб\p{L}*|rub)?\s?(\d[\d\s\u00A0.,]*)\s?(\$|usd|€|eur|₽|руб\p{L}*|rub|тыс\.?|k(?![\p{L}]))?/iu;
const BANK_CHANGE_RE = /реквизит|bank detail|new account/iu;
const REFERENCE_RE = /sp-\d{5}/iu;

const STOP_WORDS = new Set([
  'у', 'этого', 'поставщика', 'поставщик', 'какой', 'какие', 'какая', 'был', 'были', 'было', 'раньше', 'номер', 'телефон', 'в', 'договоре',
  'договор', 'контракте', 'указан', 'указанный', 'официальный', 'официального', 'официальн', 'контактный', 'правильный', 'куда', 'звонить',
  'по', 'для', 'реквизиты', 'реквизитов', 'ранее', 'прежние', 'история', 'истории', 'счёт', 'счет', 'счета', 'счёта', 'у', 'компании',
  'the', 'of', 'this', 'vendor', 'supplier', 'what', 'is', 'was', 'were', 'number', 'phone', 'in', 'contract', 'agreement', 'official',
  'callback', 'which', 'should', 'i', 'we', 'call', 'did', 'does', 'have', 'had', 'before', 'bank', 'details', 'account', 'accounts',
  'previous', 'prior', 'old', 'history', 'for', 'our', 'their', 'its', 'a', 'an', 'to', 'on', 'from', 'with', 'listed', 'stated',
]);

export function parseQuestionAmount(question: string): { amount: string; currency?: string } | null {
  const match = AMOUNT_RE.exec(question);
  if (!match) return null;
  const digits = (match[2] ?? '').trim().replace(/[\s\u00A0.,]+$/, '');
  if (!/\d/.test(digits)) return null;
  const minor = parseAmountDigits(digits, 2);
  if (minor === null) return null;
  const symbol = `${match[1] ?? ''}${match[3] ?? ''} ${question.slice((match.index ?? 0) + match[0].length, (match.index ?? 0) + match[0].length + 12)}`.toLowerCase();
  const multiplier = /тыс|k/.test(symbol) ? 1000n : 1n;
  const total = minor * multiplier;
  const amount = `${total / 100n}${total % 100n === 0n ? '' : `.${String(total % 100n).padStart(2, '0')}`}`;
  let currency: string | undefined;
  if (/\$|usd/.test(symbol)) currency = 'USD';
  else if (/€|eur/.test(symbol)) currency = 'EUR';
  else if (/₽|руб|rub/.test(symbol)) currency = 'RUB';
  return currency ? { amount, currency } : { amount };
}

export function vendorQuery(question: string): string {
  const tokens = question.replace(/[«»"“”?!.,:;()]/g, ' ').split(/\s+/).filter(Boolean);
  const kept = tokens.filter((token) => !STOP_WORDS.has(token.toLowerCase().replace(/['’]s$/, '')));
  return kept
    .join(' ')
    .replace(/['’]s(?=\s|$)/g, '')
    .trim();
}

interface VendorRow {
  id: number;
  name: string;
  similarity: number;
}

function parseResult(raw: string): Record<string, unknown> | null {
  try {
    const parsed: unknown = JSON.parse(unwrapToolResult(raw));
    return parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : null;
  } catch {
    return null;
  }
}

export function pickVendor(raw: string | undefined): VendorRow | null {
  if (!raw) return null;
  const parsed = parseResult(raw);
  if (!parsed || parsed.ambiguous === true || !Array.isArray(parsed.vendors)) return null;
  const vendors = parsed.vendors as VendorRow[];
  const top = vendors[0];
  if (!top || typeof top.id !== 'number') return null;
  if (vendors.length === 1 || top.similarity >= 45) return top;
  return null;
}

function vendorChain(detailTool: string, documentsQuery: (vendor: string) => string): (question: string, results: readonly string[]) => IntentStep | null {
  return (_question, results) => {
    const vendor = pickVendor(results[0]);
    if (!vendor) return null;
    if (results.length === 1) return { tool: detailTool, args: { vendor_id: vendor.id } };
    if (results.length === 2) return { tool: 'search_documents', args: { query: documentsQuery(vendor.name) } };
    return null;
  };
}

function thenSearch(query: string): (question: string, results: readonly string[]) => IntentStep | null {
  return (_question, results) => (results.length === 1 ? { tool: 'search_documents', args: { query } } : null);
}

const STEP_HEADING_RE = /^#{1,6}\s*(\d{1,2})[.)]\s*(.+?)\s*$/;
const NUMBERED_RE = /^(\d{1,2})[.)]\s+(.+?)\s*$/;
const BULLET_RE = /^\s*[-*•]\s+(.+?)\s*$/;

function bestDocument(sources: readonly ParsedSource[], score: (source: ParsedSource) => number, question = '', minimum = 1): ParsedSource[] {
  const byTitle = new Map<string, ParsedSource[]>();
  for (const source of sources) byTitle.set(source.title, [...(byTitle.get(source.title) ?? []), source]);
  const terms = contentTerms(question);
  let best: ParsedSource[] = [];
  let bestScore = 0;
  for (const group of byTitle.values()) {
    const items = group.reduce((sum, source) => sum + score(source), 0);
    if (items < minimum) continue;
    const relevance = terms.length > 0 ? coverage(terms, group[0]?.title ?? '') : 0;
    const total = relevance * 1000 + items;
    if (total > bestScore) {
      best = group;
      bestScore = total;
    }
  }
  return best;
}

const SECTION_SEPARATOR_RE = /\s*(?:>|›|»|\/)\s*/;

function stepHeading(line: string): { number: number; title: string } | null {
  const trimmed = line.trim();
  const match = STEP_HEADING_RE.exec(trimmed) ?? (/^(\d{1,2})[.)]\s+(\p{Lu}[^.;,:]{0,40})$/u.exec(trimmed));
  if (!match) return null;
  const title = (match[2] ?? '').trim();
  if (title.split(/\s+/).length > 4) return null;
  return { number: Number(match[1]), title };
}

function sectionStep(source: ParsedSource): { number: number; title: string } | null {
  const leaf = (source.attrs.section ?? '').split(SECTION_SEPARATOR_RE).pop() ?? '';
  return leaf === '' ? null : stepHeading(leaf.startsWith('#') ? leaf : `# ${leaf}`);
}

function firstDetail(lines: readonly string[]): string {
  const detail = lines.find((next) => next !== '' && !next.startsWith('#') && stepHeading(next) === null);
  if (!detail) return '';
  return (BULLET_RE.exec(detail)?.[1] ?? NUMBERED_RE.exec(detail)?.[2] ?? detail).replace(/\*\*/g, '');
}

export function composeProcedure(input: ComposeInput): string | null {
  const countSteps = (source: ParsedSource): number =>
    source.content.split('\n').filter((line) => stepHeading(line) !== null).length + (sectionStep(source) ? 1 : 0);
  const group = bestDocument(input.sources, countSteps, input.question, 2);
  if (group.length === 0) return null;
  const steps = new Map<number, string>();
  const add = (step: { number: number; title: string }, detail: string, ref: number): void => {
    if (steps.has(step.number)) return;
    steps.set(step.number, `${step.number}. ${step.title}${detail ? ` — ${detail}` : ''} [${ref}]`);
  };
  for (const source of group) {
    const lines = source.content.split('\n').map((line) => line.trim());
    const fromSection = sectionStep(source);
    if (fromSection) add(fromSection, firstDetail(lines), source.ref);
    lines.forEach((line, index) => {
      const heading = stepHeading(line);
      if (heading) add(heading, firstDetail(lines.slice(index + 1)), source.ref);
    });
  }
  if (steps.size < 2) return null;
  const first = group[0]!;
  const ordered = [...steps.entries()].sort((a, b) => a[0] - b[0]).map(([, line]) => line);
  const header = answerText(input.locale, 'rag.procedure', { title: first.title, ref: first.ref });
  return [...input.dataNotes.map((note) => `- ${note}`), header, '', ...ordered].join('\n').trim();
}

export function composeList(input: ComposeInput): string | null {
  const countBullets = (source: ParsedSource): number => source.content.split('\n').filter((line) => BULLET_RE.test(line)).length;
  const group = bestDocument(input.sources, countBullets, input.question, 2);
  if (group.length === 0) return null;
  const items: string[] = [];
  for (const source of group) {
    for (const line of source.content.split('\n')) {
      const bullet = BULLET_RE.exec(line);
      if (bullet && items.length < 6) items.push(`- ${(bullet[1] ?? '').replace(/\*\*/g, '')} [${source.ref}]`);
    }
  }
  if (items.length === 0) return null;
  const first = group[0]!;
  return [answerText(input.locale, 'rag.list', { title: first.title, ref: first.ref }), '', ...items].join('\n');
}

export const DOMAIN_RULES: readonly IntentRule[] = [
  {
    tool: 'evaluate_approval_policy',
    patterns: [
      /(втор\p{L}+|двух|два|сколько)\s+(соглас\p{L}+|подпис\p{L}+)|нужен ли .*соглас/iu,
      /(second|two|how many)\s+approv\p{L}*|need .*approv\p{L}*|dual (control|approval)/iu,
    ],
    buildArgs: (question) => {
      const amount = parseQuestionAmount(question) ?? { amount: '0' };
      return { ...amount, bank_change: BANK_CHANGE_RE.test(question) };
    },
    next: thenSearch('approval policy thresholds'),
  },
  {
    tool: 'find_vendor',
    patterns: [
      /(официальн\p{L}+|контактн\p{L}+|правильн\p{L}+)\s+(номер|телефон)|номер .*(договор|контракт)|куда звонить/iu,
      /official (phone|number)|phone number .*(contract|agreement)|which number .* call|callback number/iu,
    ],
    buildArgs: (question) => ({ query: vendorQuery(question) || question.slice(0, 200), limit: 3 }),
    next: vendorChain('get_vendor_official_contacts', (vendor) => `${vendor} contract contact phone`),
  },
  {
    tool: 'find_vendor',
    patterns: [
      /(реквизит\p{L}*|сч[её]т\p{L}*).*(раньше|прежн\p{L}+|ранее|истори\p{L}+|был\p{L}*)|какие .*реквизит/iu,
      /(previous|prior|old|history of).*(bank|account|details)|bank (details|account) (history|before)|what .*account .* before|(bank details|bank account|account).*\bbefore\b/iu,
    ],
    buildArgs: (question) => ({ query: vendorQuery(question) || question.slice(0, 200), limit: 3 }),
    next: vendorChain('get_vendor_bank_history', (vendor) => `${vendor} vendor master bank account`),
  },
  {
    tool: 'search_documents',
    patterns: [
      /(процедур\p{L}+|порядок|что делать|как проверить).*(нов\p{L}+|смен\p{L}+|изменени\p{L}+).*(сч[её]т\p{L}*|реквизит\p{L}*)/iu,
      /(procedure|process|what (should|do) (i|we) do|how to verify).*(new|changed?|change of).*(bank|account|details)/iu,
    ],
    buildArgs: () => ({ query: 'bank details change procedure' }),
    compose: composeProcedure,
  },
  {
    tool: 'get_payment_request',
    patterns: [/(статус|что с|где)\s.*sp-\d{5}/iu, /(status|what about|where is).*sp-\d{5}/iu],
    buildArgs: (question) => ({ reference: (REFERENCE_RE.exec(question)?.[0] ?? '').toUpperCase() }),
  },
  {
    tool: 'list_my_pending_approvals',
    patterns: [/(мои|ожидающ\p{L}+|что мне)\s.*(соглас\p{L}+|подпис\p{L}+)/iu, /(my|pending)\s+approvals|what .* (approve|sign)/iu],
    buildArgs: () => ({}),
  },
  {
    tool: 'search_documents',
    patterns: [/зарплатн\p{L}+|зарплат\p{L}+ сч[её]т/iu, /payroll|direct deposit|salary account/iu],
    buildArgs: () => ({ query: 'payroll change procedure' }),
    compose: composeProcedure,
  },
  {
    tool: 'search_documents',
    patterns: [/(признак\p{L}+|красн\p{L}+ флаг\p{L}*|как распознать).*(мошен\p{L}+|подд\p{L}+)/iu, /red flags|signs of (fraud|bec|scam)|how to spot/iu],
    buildArgs: () => ({ query: 'red flags business email compromise' }),
    compose: composeList,
  },
];
