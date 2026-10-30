import { escapeRegExp } from '../lexicon.js';

export interface Span {
  start: number;
  end: number;
}

export const WORD_START = '(?<![\\p{L}\\p{N}])';
export const WORD_END = '(?![\\p{L}\\p{N}])';

export function keywordSource(words: readonly string[]): string {
  const body = [...words]
    .sort((a, b) => b.length - a.length)
    .map((word) => {
      const stem = word.endsWith('…');
      const core = escapeRegExp(stem ? word.slice(0, -1) : word)
        .replace(/[её]/g, '[её]')
        .replace(/ /g, '[\\s\\u00A0]+');
      return stem ? `${core}[\\p{L}]*` : `${core}${WORD_END}`;
    })
    .join('|');
  return `${WORD_START}(?:${body})`;
}

export function keywordRe(words: readonly string[], flags = 'giu'): RegExp {
  return new RegExp(keywordSource(words), flags);
}

export function overlaps(a: Span, b: Span): boolean {
  return a.start < b.end && b.start < a.end;
}

export function overlapsAny(span: Span, spans: readonly Span[]): boolean {
  return spans.some((other) => overlaps(span, other));
}

export function maskSpans(text: string, spans: readonly Span[]): string {
  if (spans.length === 0) return text;
  const units = text.split('');
  for (const span of spans) {
    for (let index = Math.max(0, span.start); index < Math.min(units.length, span.end); index++) {
      if (units[index] !== '\n') units[index] = ' ';
    }
  }
  return units.join('');
}

export class TextMap {
  readonly lineStarts: number[] = [0];

  constructor(readonly text: string) {
    for (let index = 0; index < text.length; index++) {
      if (text[index] === '\n') this.lineStarts.push(index + 1);
    }
  }

  lineOf(index: number): number {
    let low = 0;
    let high = this.lineStarts.length - 1;
    while (low < high) {
      const mid = (low + high + 1) >> 1;
      if ((this.lineStarts[mid] ?? 0) <= index) low = mid;
      else high = mid - 1;
    }
    return low;
  }

  line(line: number): string {
    const start = this.lineStarts[line];
    if (start === undefined) return '';
    const next = this.lineStarts[line + 1];
    return this.text.slice(start, next === undefined ? undefined : next - 1);
  }

  lineStart(line: number): number {
    return this.lineStarts[line] ?? this.text.length;
  }

  previousNonEmptyLine(line: number): number | null {
    for (let index = line - 1; index >= 0; index--) {
      if (this.line(index).trim() !== '') return index;
    }
    return null;
  }

  nextNonEmptyLine(line: number): number | null {
    for (let index = line + 1; index < this.lineStarts.length; index++) {
      if (this.line(index).trim() !== '') return index;
    }
    return null;
  }
}

export function allMatches(re: RegExp, text: string): RegExpExecArray[] {
  const flags = re.flags.includes('g') ? re.flags : `${re.flags}g`;
  const global = new RegExp(re.source, flags);
  const out: RegExpExecArray[] = [];
  for (let match = global.exec(text); match !== null; match = global.exec(text)) {
    out.push(match);
    if (match[0] === '') global.lastIndex++;
  }
  return out;
}

export function cleanValue(value: string, max = 120): string {
  return value
    .replace(/[\s\u00A0]+/g, ' ')
    .replace(/^[\s:;,.\-–—#№]+/, '')
    .replace(/[\s:;,]+$/, '')
    .trim()
    .slice(0, max)
    .trim();
}

export function normalizeSpaces(text: string): string {
  return text.replace(/\r\n?/g, '\n').replace(/[\u2007\u202F]/g, '\u00A0').replace(/\t/g, ' ');
}
