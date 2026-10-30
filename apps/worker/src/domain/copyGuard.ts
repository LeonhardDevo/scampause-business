import forbiddenJson from './forbidden-copy.json' with { type: 'json' };
import { escapeRegExp, normalizeApostrophes } from './lexicon.js';

export interface ForbiddenCopy {
  version: string;
  negation_window: number;
  negations: string[];
  phrases: { en: string[]; ru: string[] };
  context_required: { en: string[]; ru: string[] };
}

export const FORBIDDEN_COPY: ForbiddenCopy = forbiddenJson;

export interface CopyViolation {
  phrase: string;
  index: number;
}

function phraseRe(phrase: string): RegExp {
  return new RegExp(`(?<![\\p{L}\\p{N}])${escapeRegExp(phrase).replace(/[её]/g, '[её]')}(?![\\p{L}\\p{N}])`, 'giu');
}

const NEGATION_RE = new RegExp(
  `(?<![\\p{L}\\p{N}'])(?:${FORBIDDEN_COPY.negations
    .map((word) => escapeRegExp(word))
    .sort((a, b) => b.length - a.length)
    .join('|')})(?![\\p{L}\\p{N}])`,
  'iu',
);

const ALL_PHRASES = [...FORBIDDEN_COPY.phrases.en, ...FORBIDDEN_COPY.phrases.ru];
const COMPILED = ALL_PHRASES.map((phrase) => ({ phrase, re: phraseRe(phrase) }));

export function isNegated(text: string, index: number, window = FORBIDDEN_COPY.negation_window): boolean {
  const before = normalizeApostrophes(text.slice(Math.max(0, index - window), index));
  return NEGATION_RE.test(before);
}

export function findForbiddenCopy(text: string): CopyViolation[] {
  const normalized = normalizeApostrophes(text);
  const violations: CopyViolation[] = [];
  for (const { phrase, re } of COMPILED) {
    re.lastIndex = 0;
    for (let match = re.exec(normalized); match !== null; match = re.exec(normalized)) {
      if (!isNegated(normalized, match.index)) violations.push({ phrase, index: match.index });
    }
  }
  return violations.sort((a, b) => a.index - b.index);
}

export function hasForbiddenCopy(text: string): boolean {
  return findForbiddenCopy(text).length > 0;
}

const EMOJI_RE = /\p{Emoji_Presentation}|\uFE0F/u;

export function containsEmoji(text: string): boolean {
  return EMOJI_RE.test(text);
}
