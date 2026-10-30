import lexiconJson from './lexicon.json' with { type: 'json' };

export type MatchMode = 'word' | 'prefix';

export interface LexiconPhrase {
  text: string;
  mode: MatchMode;
  locale: string;
}

export interface LexiconCategory {
  points: number;
  scope: string;
  phrases: LexiconPhrase[];
}

export interface Lexicon {
  version: string;
  categories: Record<string, LexiconCategory>;
  kind: { payroll: LexiconPhrase[]; change: LexiconPhrase[]; wire: LexiconPhrase[] };
  content_points_cap: number;
  bank_change_signals: string[];
  web_only_signals: string[];
}

export const LEXICON: Lexicon = lexiconJson as Lexicon;

export const BANK_CHANGE_SIGNALS: ReadonlySet<string> = new Set(LEXICON.bank_change_signals);
export const WEB_ONLY_SIGNALS: ReadonlySet<string> = new Set(LEXICON.web_only_signals);

const ESCAPE_RE = /[.*+?^${}()|[\]\\]/g;

export function escapeRegExp(value: string): string {
  return value.replace(ESCAPE_RE, '\\$&');
}

export function normalizeApostrophes(text: string): string {
  return text.replace(/[‘’ʼ]/g, "'");
}

export function phrasePattern(phrase: LexiconPhrase): string {
  const tokens = phrase.text.trim().split(/\s+/);
  const parts = tokens.map((token) => {
    const stem = token.endsWith('…');
    const core = escapeRegExp(stem ? token.slice(0, -1) : token).replace(/[её]/g, '[её]');
    return stem ? `${core}[\\p{L}\\p{N}]*` : core;
  });
  const tail = phrase.mode === 'word' ? '(?![\\p{L}\\p{N}])' : '';
  return `(?<![\\p{L}\\p{N}])${parts.join('[\\s\\u00A0]+')}${tail}`;
}

const compiled = new Map<LexiconPhrase, RegExp>();

export function phraseRegExp(phrase: LexiconPhrase): RegExp {
  let re = compiled.get(phrase);
  if (!re) {
    re = new RegExp(phrasePattern(phrase), 'giu');
    compiled.set(phrase, re);
  }
  re.lastIndex = 0;
  return re;
}

export function matchPhrases(text: string, phrases: readonly LexiconPhrase[], limit = 3): string[] {
  const normalized = normalizeApostrophes(text);
  const found: string[] = [];
  for (const phrase of phrases) {
    const match = phraseRegExp(phrase).exec(normalized);
    if (match && !found.includes(match[0].toLowerCase())) found.push(match[0].toLowerCase());
    if (found.length >= limit) break;
  }
  return found;
}

export function matchesAny(text: string, phrases: readonly LexiconPhrase[]): boolean {
  return matchPhrases(text, phrases, 1).length > 0;
}

export function categoryPhrases(code: string): LexiconPhrase[] {
  return LEXICON.categories[code]?.phrases ?? [];
}
