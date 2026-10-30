import en from '../prompts/domain/mock_answers.en.json' with { type: 'json' };
import ru from '../prompts/domain/mock_answers.ru.json' with { type: 'json' };
import type { RagLocale } from '../answer/i18n.js';

type Tree = { [key: string]: string | Tree };

const CATALOGS: Record<RagLocale, Tree> = { en, ru };
const PLURALS: Record<RagLocale, Intl.PluralRules> = { en: new Intl.PluralRules('en'), ru: new Intl.PluralRules('ru') };

export type AnswerParams = Record<string, string | number>;

function lookup(tree: Tree, key: string): string | Tree | undefined {
  let node: string | Tree | undefined = tree;
  for (const part of key.split('.')) {
    if (node === undefined || typeof node === 'string') return undefined;
    node = node[part];
  }
  return node;
}

function interpolate(template: string, params: AnswerParams): string {
  return template.replace(/\{(\w+)\}/g, (match, name: string) => (name in params ? String(params[name]) : match));
}

export function answerText(locale: RagLocale, key: string, params: AnswerParams = {}): string {
  const node = lookup(CATALOGS[locale], key) ?? lookup(CATALOGS.en, key);
  if (typeof node !== 'string') return key;
  return interpolate(node, params);
}

export function answerPlural(locale: RagLocale, key: string, count: number, params: AnswerParams = {}): string {
  const node = lookup(CATALOGS[locale], key) ?? lookup(CATALOGS.en, key);
  if (node === undefined) return key;
  if (typeof node === 'string') return interpolate(node, { ...params, count, n: count });
  const category = PLURALS[locale].select(count);
  const form = node[category] ?? node.many ?? node.other ?? node.one;
  return typeof form === 'string' ? interpolate(form, { ...params, count, n: count }) : key;
}

export function answerCatalog(locale: RagLocale): Tree {
  return CATALOGS[locale];
}
