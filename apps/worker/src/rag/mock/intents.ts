import type { IntentRule } from '../providers/mock.js';
import { DOMAIN_RULES } from './intentsDomain.js';

export const FOUNDATION_RULES: readonly IntentRule[] = [
  {
    tool: 'search_documents',
    patterns: [
      /^(?:please\s+)?(?:find|search(?:\s+for)?|look\s+up)\s+(.{3,})$/iu,
      /^(?:пожалуйста\s+)?(?:найди|найти|поищи|ищи|поиск)\s+(.{3,})$/iu,
    ],
    buildArgs: (_question, match) => ({ query: (match[1] ?? '').trim().replace(/^[ ?.!]+|[ ?.!]+$/g, '') }),
  },
];

export const INTENT_RULES: readonly IntentRule[] = [...DOMAIN_RULES, ...FOUNDATION_RULES];
