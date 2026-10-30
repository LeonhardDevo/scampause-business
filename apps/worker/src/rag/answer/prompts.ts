import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import type { RagLocale } from './i18n.js';

export const PROMPTS_DIR = fileURLToPath(new URL('../prompts/', import.meta.url));

const cache = new Map<string, string>();

function read(name: string): string {
  let text = cache.get(name);
  if (text === undefined) {
    text = readFileSync(`${PROMPTS_DIR}${name}`, 'utf8').replace(/<!--[\s\S]*?-->/g, '').trim();
    cache.set(name, text);
  }
  return text;
}

export function systemPrompt(locale: RagLocale, productName: string, today: Date = new Date()): string {
  const values: Record<string, string> = {
    product_name: productName,
    domain: read(`domain.${locale}.md`),
    today: today.toISOString().slice(0, 10),
  };
  return read(`system.${locale}.md`).replace(/\$(\w+)/g, (match, name: string) => values[name] ?? match);
}
