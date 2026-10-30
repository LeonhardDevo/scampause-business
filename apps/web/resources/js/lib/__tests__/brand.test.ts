import { describe, expect, it } from 'vitest';
import {
    COOKIE_NOTICE_NAME,
    hasCookie,
    noticeCookieValue,
} from '@/lib/cookie-notice';
import {
    clampScore,
    maskAccount,
    riskLevelFor,
    severityBars,
} from '@/lib/risk';
import { parseDemoAmount, simulateDemoPolicy } from '@/lib/policy-demo';

describe('risk helpers', () => {
    it('maps scores to the contract levels', () => {
        expect(riskLevelFor(0)).toBe('low');
        expect(riskLevelFor(24)).toBe('low');
        expect(riskLevelFor(25)).toBe('medium');
        expect(riskLevelFor(49)).toBe('medium');
        expect(riskLevelFor(50)).toBe('high');
        expect(riskLevelFor(74)).toBe('high');
        expect(riskLevelFor(75)).toBe('critical');
        expect(riskLevelFor(100)).toBe('critical');
    });

    it('clamps and rounds scores', () => {
        expect(clampScore(-5)).toBe(0);
        expect(clampScore(120)).toBe(100);
        expect(clampScore(91.6)).toBe(92);
        expect(clampScore(Number.NaN)).toBe(0);
    });

    it('turns signal points into one to four severity bars', () => {
        expect(severityBars(5)).toBe(1);
        expect(severityBars(11)).toBe(2);
        expect(severityBars(21)).toBe(3);
        expect(severityBars(36)).toBe(4);
    });

    it('masks account numbers down to the last four characters', () => {
        expect(maskAccount('LT58 3250 0000 0000 9129')).toBe('•••• 9129');
        expect(maskAccount('4417')).toBe('4417');
    });
});

describe('cookie notice', () => {
    it('detects the dismissal cookie by exact name', () => {
        expect(hasCookie('a=1; cookie_notice=1', COOKIE_NOTICE_NAME)).toBe(
            true,
        );
        expect(hasCookie('cookie_notice_x=1', COOKIE_NOTICE_NAME)).toBe(false);
        expect(hasCookie('', COOKIE_NOTICE_NAME)).toBe(false);
    });

    it('writes a long-lived first-party cookie', () => {
        expect(noticeCookieValue(false)).toBe(
            'cookie_notice=1;path=/;max-age=31536000;SameSite=Lax',
        );
        expect(noticeCookieValue(true)).toContain(';Secure');
    });
});

describe('demo policy simulator', () => {
    it('requires more control as the amount grows', () => {
        expect(simulateDemoPolicy(500)).toMatchObject({
            approvers: 1,
            callbackRequired: false,
            ownerRequired: false,
        });
        expect(simulateDemoPolicy(3000)).toMatchObject({
            approvers: 2,
            callbackRequired: true,
            ownerRequired: false,
        });
        expect(simulateDemoPolicy(18400)).toMatchObject({
            approvers: 2,
            callbackRequired: true,
            ownerRequired: true,
        });
    });

    it('treats invalid amounts as zero', () => {
        expect(simulateDemoPolicy(Number.NaN).approvers).toBe(1);
        expect(parseDemoAmount('18 400,50')).toBe(18400.5);
        expect(parseDemoAmount('abc')).toBe(0);
        expect(parseDemoAmount('-5')).toBe(0);
    });
});
