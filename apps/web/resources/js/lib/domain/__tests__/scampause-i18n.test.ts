import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { I18n } from 'laravel-vue-i18n';
import { parseAll } from 'laravel-vue-i18n/loader';
import { beforeAll, describe, expect, it } from 'vitest';
import guard from '@/lib/domain/forbidden-copy.json';
import { tc } from '@/lib/i18n';

const here = path.dirname(fileURLToPath(import.meta.url));
const webRoot = path.resolve(here, '../../../../..');
const langDir = path.join(webRoot, 'lang');
const sourceDir = path.join(webRoot, 'resources/js');

type Dictionary = Record<string, string>;

const parsed = parseAll(langDir);
const dictionaries: Record<'en' | 'ru', Dictionary> = {
    en: parsed.find((file) => file.name === 'php_en.json')?.translations ?? {},
    ru: parsed.find((file) => file.name === 'php_ru.json')?.translations ?? {},
};

function owned(dictionary: Dictionary): string[] {
    return Object.keys(dictionary).filter((key) =>
        key.startsWith('scampause.'),
    );
}

function placeholders(message: string): string[] {
    return [...new Set(message.match(/:[a-z_]+/gi) ?? [])].sort();
}

function sourceFiles(dir: string): string[] {
    const skip = new Set(['actions', 'routes', 'wayfinder', '__tests__']);

    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            return skip.has(entry.name) ? [] : sourceFiles(full);
        }

        return /\.(vue|ts)$/.test(entry.name) ? [full] : [];
    });
}

function referencedKeys(): string[] {
    const keys = new Set<string>();
    const pattern = /'(scampause\.[a-z0-9_]+(?:\.[a-z0-9_]+)+)'/g;

    for (const file of sourceFiles(sourceDir)) {
        for (const match of fs.readFileSync(file, 'utf8').matchAll(pattern)) {
            keys.add(match[1]);
        }
    }

    return [...keys].sort();
}

const DYNAMIC_FAMILIES: Record<string, string[]> = {
    'scampause.bank_diff.fields.%': [
        'scheme',
        'account',
        'routing',
        'bic',
        'bank_name',
        'bank_country',
        'holder',
        'currency',
    ],
    'scampause.account.checksum_%': ['ok', 'invalid', 'incomplete'],
    'scampause.approvals.tabs.%': ['pending', 'decided'],
    'scampause.dashboard.onboarding.%': [
        'vendors',
        'callback',
        'rules',
        'telegram',
        'approver',
    ],
    'scampause.extraction.fields.%': [
        'vendor_name',
        'invoice_number',
        'invoice_date',
        'due_date',
        'amount',
        'payee_last4',
    ],
    'scampause.extraction.sources.%': [
        'text',
        'ocr',
        'qr',
        'eml_header',
        'llm_assist',
        'manual',
    ],
    'scampause.intake.issues.%': [
        'too_many',
        'too_large',
        'total_too_large',
        'unsupported',
        'empty',
        'duplicate',
    ],
    'scampause.policies.rate_source.%': ['seed', 'manual'],
    'scampause.reports.buckets.%': ['lt4h', 'h4_24', 'd1_2', 'd2_3', 'gt3d'],
    'scampause.reports.presets.%': ['30d', '90d', 'ytd', '12m'],
    'scampause.scc.%_title': ['stop', 'call', 'confirm'],
    'scampause.scc.%_body': ['stop', 'call', 'confirm'],
    'scampause.sender.lookalike.%': [
        'added',
        'removed',
        'replaced',
        'swapped',
        'homoglyph',
        'affix',
        'tld_swap',
        'same',
    ],
    'scampause.sender.roles.%': ['from', 'reply_to', 'return_path', 'link'],
    'scampause.timeline.delivery_status.%': ['sent', 'failed', 'queued'],
    'scampause.evidence_keys.%': [
        'last4',
        'domain',
        'reason',
        'method',
        'outcome',
        'phones_last4',
    ],
    'roles.%': [
        'owner',
        'admin',
        'approver',
        'bookkeeper',
        'member',
        'auditor',
    ],
};

const json: Record<'en' | 'ru', Dictionary> = {
    en: JSON.parse(
        fs.readFileSync(path.join(langDir, 'en.json'), 'utf8'),
    ) as Dictionary,
    ru: JSON.parse(
        fs.readFileSync(path.join(langDir, 'ru.json'), 'utf8'),
    ) as Dictionary,
};

function has(locale: 'en' | 'ru', key: string): boolean {
    return key in dictionaries[locale] || key in json[locale];
}

beforeAll(() => {
    I18n.getSharedInstance(
        {
            lang: 'ru',
            fallbackLang: 'en',
            resolve: (lang: string) =>
                lang.startsWith('php_')
                    ? {}
                    : {
                          ...dictionaries[lang as 'en' | 'ru'],
                          ...json[lang as 'en' | 'ru'],
                      },
        },
        true,
    );
});

describe('scampause translations', () => {
    it('have the same keys in English and Russian', () => {
        const en = owned(dictionaries.en);
        const ru = owned(dictionaries.ru);

        expect(en.filter((key) => !(key in dictionaries.ru))).toEqual([]);
        expect(ru.filter((key) => !(key in dictionaries.en))).toEqual([]);
        expect(en.length).toBeGreaterThan(600);
    });

    it('use the same placeholders in both locales', () => {
        const mismatched = owned(dictionaries.en).filter(
            (key) =>
                placeholders(dictionaries.en[key]).join() !==
                placeholders(dictionaries.ru[key] ?? '').join(),
        );

        expect(mismatched).toEqual([]);
    });

    it('contain every key referenced in the source code', () => {
        const missing = referencedKeys().flatMap((key) =>
            (['en', 'ru'] as const)
                .filter((locale) => !has(locale, key))
                .map((locale) => `${locale}:${key}`),
        );

        expect(missing).toEqual([]);
    });

    it('contain every runtime-built key', () => {
        const missing: string[] = [];

        for (const [pattern, values] of Object.entries(DYNAMIC_FAMILIES)) {
            for (const value of values) {
                const key = pattern.replace('%', value);

                for (const locale of ['en', 'ru'] as const) {
                    if (!has(locale, key)) {
                        missing.push(`${locale}:${key}`);
                    }
                }
            }
        }

        expect(missing).toEqual([]);
    });

    it('give Russian plurals three forms whenever English has two', () => {
        const wrong = owned(dictionaries.en).filter((key) => {
            const en = dictionaries.en[key].split('|').length;
            const ru = (dictionaries.ru[key] ?? '').split('|').length;

            return en > 1 && ru !== 3;
        });

        expect(wrong).toEqual([]);
    });

    it('pick the correct Russian plural form', () => {
        expect(tc('scampause.requests.total', 1)).toBe('1 запрос');
        expect(tc('scampause.requests.total', 3)).toBe('3 запроса');
        expect(tc('scampause.requests.total', 5)).toBe('5 запросов');
        expect(tc('scampause.requests.total', 21)).toBe('21 запрос');
        expect(tc('scampause.requests.total', 12)).toBe('12 запросов');
    });

    it('never promise safety or use banned wording', () => {
        const offenders: string[] = [];

        for (const locale of ['en', 'ru'] as const) {
            const phrases = guard.phrases[locale];
            const context = guard.context_required[locale];

            for (const key of owned(dictionaries[locale])) {
                const text = dictionaries[locale][key].toLowerCase();

                for (const phrase of [...phrases, ...context]) {
                    if (text.includes(phrase.toLowerCase())) {
                        offenders.push(`${locale}:${key}:${phrase}`);
                    }
                }
            }
        }

        expect(offenders).toEqual([]);
    });

    it('contain no emoji', () => {
        const emoji =
            /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{1F000}-\u{1F2FF}]/u;
        const offenders = (['en', 'ru'] as const).flatMap((locale) =>
            owned(dictionaries[locale])
                .filter((key) => emoji.test(dictionaries[locale][key]))
                .map((key) => `${locale}:${key}`),
        );

        expect(offenders).toEqual([]);
    });
});
