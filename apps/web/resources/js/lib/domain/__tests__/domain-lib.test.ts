import { describe, expect, it } from 'vitest';
import { nextIndex } from '@/composables/domain/useRowKeys';
import { pipelineChanged } from '@/composables/domain/usePipelinePolling';
import {
    abaValid,
    accountChecksum,
    bicMatchesIban,
    bicValid,
    groupByFour,
    ibanState,
    ibanValid,
    routingChecksum,
    routingRequired,
    ruAccountValid,
} from '@/lib/domain/bank-validators';
import { deadlineTone, remaining } from '@/lib/domain/deadline';
import {
    buildExcerpt,
    countMatches,
    decodeEntities,
    parseExcerpt,
} from '@/lib/domain/excerpt';
import {
    acceptAttribute,
    fileKind,
    validateIntakeFiles,
} from '@/lib/domain/intake';
import { describeLookalike, diffSegments } from '@/lib/domain/lookalike';
import {
    exponent,
    formatMinor,
    minorToInput,
    normalizeAmountInput,
    parseAmount,
} from '@/lib/domain/money';
import { isRelativeAppPath, safeAppUrl } from '@/lib/domain/open-url';
import {
    moveItem,
    offsetsValid,
    parseOffsets,
    ruleConditions,
    ruleRequirements,
} from '@/lib/domain/policy';
import {
    groupSignals,
    hasWebOnlySignal,
    messagePhones,
    pressurePhrases,
    totalPoints,
} from '@/lib/domain/signals';
import { isOpen, isProcessing, STATUS_META } from '@/lib/domain/status';
import type { PipelineStatus, RiskSignal, RuleDto } from '@/types/domain';

describe('bank validators (contract 2.3.3 vectors)', () => {
    it.each([
        'DE89370400440532013000',
        'LT093250024720018839',
        'ES7921000813610123456789',
        'GB82BARC20035544332211',
    ])('accepts IBAN %s', (iban) => {
        expect(ibanValid(iban)).toBe(true);
        expect(ibanValid(groupByFour(iban))).toBe(true);
        expect(ibanState(iban)).toBe('ok');
    });

    it('rejects IBANs with a wrong check digit or length', () => {
        expect(ibanValid('DE89370400440532013001')).toBe(false);
        expect(ibanValid('DE8937040044053201300')).toBe(false);
        expect(ibanState('DE89 3704')).toBe('incomplete');
        expect(ibanState('DE00370400440532013000')).toBe('invalid');
    });

    it.each(['021000021', '121000248', '026009593'])(
        'accepts ABA %s',
        (aba) => {
            expect(abaValid(aba)).toBe(true);
            expect(routingChecksum('us_ach', aba)).toBe('ok');
        },
    );

    it('rejects a broken ABA number', () => {
        expect(abaValid('021000022')).toBe(false);
        expect(abaValid('000000000')).toBe(false);
        expect(routingChecksum('us_ach', '0210')).toBe('incomplete');
    });

    it('checks the Russian account key against the BIK', () => {
        expect(ruAccountValid('40702810938000012345', '044525225')).toBe(true);
        expect(ruAccountValid('40702810938000012346', '044525225')).toBe(false);
        expect(
            accountChecksum('ru_account', '40702810938000012345', '044525225'),
        ).toBe('ok');
        expect(accountChecksum('ru_account', '4070281093', '044525225')).toBe(
            'incomplete',
        );
        expect(accountChecksum('ru_account', '40702810938000012345', '')).toBe(
            'incomplete',
        );
    });

    it('validates BIC format and its IBAN country', () => {
        expect(bicValid('DEUTDEFF')).toBe(true);
        expect(bicValid('DEUTDEFF500')).toBe(true);
        expect(bicValid('DEUT1EFF')).toBe(false);
        expect(bicMatchesIban('DEUTDEFF', 'DE89370400440532013000')).toBe(true);
        expect(bicMatchesIban('BARCGB22', 'DE89370400440532013000')).toBe(
            false,
        );
    });

    it('knows which schemes need a routing code', () => {
        expect(routingRequired('us_ach')).toBe(true);
        expect(routingRequired('ru_account')).toBe(true);
        expect(routingRequired('uk_sort_code')).toBe(true);
        expect(routingRequired('iban')).toBe(false);
        expect(accountChecksum('other', '123')).toBe('not_applicable');
    });
});

describe('money', () => {
    it('formats integer minor units per locale', () => {
        expect(formatMinor(1840000, 'USD', 'en')).toBe('$18,400.00');
        expect(formatMinor(1840000, 'USD', 'ru')).toBe('18 400,00 $');
        expect(formatMinor(150050, 'RUB', 'ru')).toBe('1 500,50 ₽');
        expect(formatMinor(5000, 'JPY', 'en')).toBe('¥5,000');
        expect(formatMinor(-1234, 'EUR', 'en')).toBe('-€12.34');
        expect(formatMinor(100, 'CHF', 'en')).toBe('CHF 1.00');
    });

    it('parses user input into minor units without floats', () => {
        expect(parseAmount('18 400,50', 'EUR')).toBe(1840050);
        expect(parseAmount('18,400.50', 'USD')).toBe(1840050);
        expect(parseAmount('1.234.567,89', 'EUR')).toBe(123456789);
        expect(parseAmount('0.1', 'USD')).toBe(10);
        expect(parseAmount('1.005', 'USD')).toBe(null);
        expect(parseAmount('5000', 'JPY')).toBe(5000);
        expect(parseAmount('50.5', 'JPY')).toBe(null);
        expect(parseAmount('abc', 'USD')).toBe(null);
        expect(normalizeAmountInput("1'000.25")).toBe('1000.25');
    });

    it('round-trips minor units into input strings', () => {
        expect(minorToInput(1840050, 'USD')).toBe('18400.50');
        expect(minorToInput(5, 'USD')).toBe('0.05');
        expect(minorToInput(5000, 'JPY')).toBe('5000');
        expect(minorToInput(null, 'USD')).toBe('');
        expect(exponent('jpy')).toBe(0);
    });
});

describe('look-alike domains', () => {
    it('describes an added character', () => {
        const result = describeLookalike(
            'blueridge-pack.com',
            'blueridgepack.com',
        );

        expect(result.kind).toBe('added');
        expect(result.count).toBe(1);
        expect(
            result.segments
                .filter((segment) => segment.changed)
                .map((segment) => segment.text),
        ).toEqual(['-']);
    });

    it('describes removed, swapped and replaced characters', () => {
        expect(describeLookalike('northwnd.com', 'northwind.com').kind).toBe(
            'removed',
        );
        expect(describeLookalike('nortwhind.com', 'northwind.com').kind).toBe(
            'swapped',
        );
        expect(describeLookalike('northw1nd.com', 'northwind.com').kind).toBe(
            'replaced',
        );
        expect(
            describeLookalike('northwind.co', 'northwind.com', 'tld_swap').kind,
        ).toBe('tld_swap');
        expect(describeLookalike('northwind.com', 'NorthWind.com').kind).toBe(
            'same',
        );
    });

    it('highlights only inserted characters', () => {
        expect(diffSegments('abc', 'abxc')).toEqual([
            { text: 'ab', changed: false },
            { text: 'x', changed: true },
            { text: 'c', changed: false },
        ]);
    });
});

describe('source excerpt', () => {
    it('splits server marks and decodes entities', () => {
        const segments = parseExcerpt(
            'Pay &quot;now&quot; to <mark>8830</mark> today',
        );

        expect(segments).toEqual([
            { text: 'Pay "now" to ', kind: 'plain' },
            { text: '8830', kind: 'value' },
            { text: ' today', kind: 'plain' },
        ]);
        expect(decodeEntities('&#1046;&amp;&#x41;')).toBe('Ж&A');
    });

    it('highlights pressure phrases on word boundaries, Russian included', () => {
        const segments = buildExcerpt(
            'Срочно оплатите сегодня. Не звоните нам, urgent payment',
            ['срочно', 'не звоните', 'urgent'],
        );

        expect(countMatches(segments)).toBe(3);
        expect(
            segments
                .filter((segment) => segment.kind === 'phrase')
                .map((segment) => segment.text),
        ).toEqual(['Срочно', 'Не звоните', 'urgent']);
        expect(countMatches(buildExcerpt('unurgently', ['urgent']))).toBe(0);
    });

    it('does not interpret markup other than marks', () => {
        const segments = parseExcerpt('<script>alert(1)</script>text');

        expect(segments.map((segment) => segment.text).join('')).toBe(
            'alert(1)text',
        );
    });
});

function signal(partial: Partial<RiskSignal>): RiskSignal {
    return {
        code: 'X',
        category: 'bank',
        severity: 'low',
        points: 5,
        title: 'Title',
        explanation: '',
        advice: '',
        evidence: {},
        ...partial,
    };
}

describe('signals', () => {
    const signals = [
        signal({
            code: 'URGENCY',
            category: 'content',
            points: 10,
            evidence: { phrases: ['urgent', 'today'] },
        }),
        signal({
            code: 'BANK_DETAILS_CHANGED',
            category: 'bank',
            severity: 'critical',
            points: 40,
        }),
        signal({
            code: 'BANK_ACCOUNT_FIRST_USE',
            category: 'bank',
            points: 15,
        }),
        signal({
            code: 'DONT_CALL',
            category: 'content',
            points: 20,
            evidence: {
                phrases: ['do not call', 'URGENT'],
                phones_last4: ['1234'],
            },
        }),
    ];

    it('groups by category in fixed order and sorts by points', () => {
        const groups = groupSignals(signals);

        expect(groups.map((group) => group.category)).toEqual([
            'bank',
            'content',
        ]);
        expect(groups[0].signals.map((item) => item.code)).toEqual([
            'BANK_DETAILS_CHANGED',
            'BANK_ACCOUNT_FIRST_USE',
        ]);
        expect(groups[0].points).toBe(55);
        expect(groups[0].topSeverity).toBe('critical');
        expect(totalPoints(signals)).toBe(85);
    });

    it('collects unique pressure phrases longest first and message phones', () => {
        expect(pressurePhrases(signals)).toEqual([
            'do not call',
            'urgent',
            'today',
        ]);
        expect(messagePhones(signals)).toEqual(['1234']);
    });

    it('detects web-only signals', () => {
        expect(hasWebOnlySignal(['URGENCY', null])).toBe(false);
        expect(hasWebOnlySignal(['BANK_DETAILS_CHANGED'])).toBe(true);
    });
});

describe('status and deadlines', () => {
    it('classifies open and processing states', () => {
        expect(isProcessing('received')).toBe(true);
        expect(isProcessing('review')).toBe(false);
        expect(isOpen('awaiting_approval')).toBe(true);
        expect(isOpen('paid')).toBe(false);
        expect(STATUS_META.approved.tone).toBe('success');
        expect(STATUS_META.rejected.tone).toBe('destructive');
    });

    it('turns a deadline warning below 12 hours and critical below 2 hours', () => {
        const now = new Date('2026-10-23T10:00:00Z');

        expect(deadlineTone('2026-10-24T10:00:00Z', now)).toBe('normal');
        expect(deadlineTone('2026-10-23T20:00:00Z', now)).toBe('warning');
        expect(deadlineTone('2026-10-23T11:00:00Z', now)).toBe('critical');
        expect(deadlineTone('2026-10-23T09:00:00Z', now)).toBe('expired');
        expect(deadlineTone(null, now)).toBe('none');
        expect(remaining('2026-10-24T13:30:00Z', now)).toEqual({
            days: 1,
            hours: 3,
            minutes: 30,
            totalMs: 99_000_000,
        });
    });
});

describe('intake files', () => {
    const accept = ['.pdf', '.png', '.jpg', '.jpeg', '.webp', '.eml'];

    it('accepts supported files and reports problems', () => {
        const mb = 1024 * 1024;
        const result = validateIntakeFiles(
            [
                { name: 'invoice.PDF', size: mb },
                { name: 'mail.eml', size: 2 * mb },
                { name: 'virus.exe', size: 10 },
                { name: 'huge.png', size: 16 * mb },
                { name: 'invoice.PDF', size: mb },
                { name: 'empty.jpg', size: 0 },
            ],
            accept,
            5,
            15,
        );

        expect(result.accepted.map((file) => file.name)).toEqual([
            'invoice.PDF',
            'mail.eml',
        ]);
        expect(result.issues.map((issue) => issue.code)).toEqual([
            'unsupported',
            'too_large',
            'duplicate',
            'empty',
        ]);
    });

    it('enforces the file count and the total size', () => {
        const files = Array.from({ length: 7 }, (_, index) => ({
            name: `f${index}.pdf`,
            size: 9 * 1024 * 1024,
        }));
        const result = validateIntakeFiles(files, accept, 5, 15);

        expect(result.accepted).toHaveLength(4);
        expect(result.issues.map((issue) => issue.code)).toEqual([
            'total_too_large',
            'total_too_large',
            'total_too_large',
        ]);

        const small = Array.from({ length: 7 }, (_, index) => ({
            name: `s${index}.png`,
            size: 1024,
        }));

        expect(
            validateIntakeFiles(small, accept, 5, 15).issues.map(
                (issue) => issue.code,
            ),
        ).toEqual(['too_many', 'too_many']);
    });

    it('builds the accept attribute and detects kinds', () => {
        expect(acceptAttribute(['pdf', '.eml'])).toBe('.pdf,.eml');
        expect(fileKind({ name: 'a.webp', size: 1 })).toBe('image');
        expect(fileKind({ name: 'a.eml', size: 1 })).toBe('eml');
        expect(fileKind({ name: 'a.docx', size: 1 })).toBe('other');
    });
});

describe('policy helpers', () => {
    const rule: RuleDto = {
        id: 1,
        name: 'Large payments',
        description: null,
        position: 1,
        is_active: true,
        version: 2,
        min_amount: { minor: 300000, currency: 'USD', formatted: '$3,000.00' },
        max_amount: null,
        kinds: null,
        min_risk_level: null,
        applies_to_new_vendor: true,
        applies_to_bank_change: null,
        approvals_required: 2,
        required_roles: ['owner'],
        approver_roles: ['owner', 'admin'],
        verification_required: true,
        expiry_hours: 24,
        updated_by: null,
        updated_at: '2026-10-01T00:00:00Z',
    };

    it('reads a rule as conditions and requirements', () => {
        expect(ruleConditions(rule)).toHaveLength(2);
        expect(ruleRequirements(rule)).toHaveLength(4);
        expect(
            ruleConditions({
                ...rule,
                min_amount: null,
                applies_to_new_vendor: null,
            }),
        ).toHaveLength(1);
    });

    it('moves items for reordering without mutating', () => {
        const items = [1, 2, 3, 4];

        expect(moveItem(items, 0, 2)).toEqual([2, 3, 1, 4]);
        expect(moveItem(items, 3, 0)).toEqual([4, 1, 2, 3]);
        expect(moveItem(items, 0, 9)).toEqual([1, 2, 3, 4]);
        expect(items).toEqual([1, 2, 3, 4]);
    });

    it('parses and validates reminder offsets', () => {
        expect(parseOffsets('24, 4; 12 4')).toEqual([4, 12, 24]);
        expect(parseOffsets('4, x')).toBe(null);
        expect(offsetsValid([4, 12, 24], 48)).toBe(true);
        expect(offsetsValid([4, 48], 48)).toBe(false);
        expect(offsetsValid([1, 2, 3, 4, 5], 48)).toBe(false);
    });
});

describe('composable helpers', () => {
    const base: PipelineStatus = {
        status: 'processing',
        revision: 1,
        stages: {
            extract: { state: 'done', at: null, error: null },
            domain: { state: 'running', at: null, error: null },
            bank: { state: 'pending', at: null, error: null },
            score: { state: 'pending', at: null, error: null },
        },
        risk: null,
    };

    it('notices pipeline progress', () => {
        expect(pipelineChanged(base, { ...base })).toBe(false);
        expect(
            pipelineChanged(base, {
                ...base,
                stages: {
                    ...base.stages,
                    domain: { state: 'done', at: null, error: null },
                },
            }),
        ).toBe(true);
        expect(pipelineChanged(base, { ...base, status: 'review' })).toBe(true);
    });

    it('moves row focus within bounds', () => {
        expect(nextIndex(-1, 5, 1)).toBe(0);
        expect(nextIndex(-1, 5, -1)).toBe(4);
        expect(nextIndex(4, 5, 1)).toBe(4);
        expect(nextIndex(0, 5, -1)).toBe(0);
        expect(nextIndex(0, 0, 1)).toBe(-1);
    });
});

describe('assistant open_url guard', () => {
    it('accepts app-relative domain links only', () => {
        expect(safeAppUrl('/payment-requests/42')).toBe('/payment-requests/42');
        expect(safeAppUrl('/approvals?tab=decided')).toBe(
            '/approvals?tab=decided',
        );
        expect(safeAppUrl('/dashboard')).toBe('/dashboard');
        expect(safeAppUrl('//evil.example/payment-requests')).toBeNull();
        expect(safeAppUrl('https://evil.example')).toBeNull();
        expect(safeAppUrl('/settings/billing')).toBeNull();
        expect(safeAppUrl('/vendorsX')).toBeNull();
        expect(safeAppUrl('/payment-requests/<script>')).toBeNull();
        expect(safeAppUrl(42)).toBeNull();
        expect(isRelativeAppPath('//evil.example')).toBe(false);
        expect(isRelativeAppPath('/knowledge/1')).toBe(true);
    });
});

describe('risk floors', () => {
    it('names the minimum level each floor signal enforces', async () => {
        const { floorLevel } = await import('@/lib/domain/signals');

        expect(floorLevel('BANK_DETAILS_CHANGED')).toBe('high');
        expect(floorLevel('QR_PAYEE_MISMATCH')).toBe('high');
        expect(floorLevel('SENDER_DOMAIN_LOOKALIKE')).toBe('critical');
        expect(floorLevel('EMPLOYEE_DENIED_CHANGE')).toBe('critical');
        expect(floorLevel('URGENCY_LANGUAGE')).toBeNull();
        expect(floorLevel(null)).toBeNull();
    });
});
