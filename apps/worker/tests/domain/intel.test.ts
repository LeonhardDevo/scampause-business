import { domainToASCII } from 'node:url';
import { describe, expect, it, vi } from 'vitest';
import { loadDomainConfig } from '../../src/domain/config.js';
import { assertIntelDriverAllowed, FakeDomainIntelDriver, LiveDomainIntelDriver, parseDmarc, parseRdap, type DnsResolver } from '../../src/domain/intel/drivers.js';
import { damerauLevenshtein, findLookalikes, isFreeMail, lookalike, registrableParts, skeleton } from '../../src/domain/intel/lookalike.js';

const NOW = new Date('2026-10-23T12:00:00.000Z');

describe('lookalike detection', () => {
  it.each([
    ['blueridge-pack.com', 'blueridgepack.com', 'edit_distance'],
    ['blueridgepak.com', 'blueridgepack.com', 'edit_distance'],
    ['harbor-lg.com', 'harborlg.com', 'edit_distance'],
    ['andesgreen-coffee.com', 'andesgreencoffee.com', 'edit_distance'],
    ['teplo-servis.ru', 'teploservis.ru', 'edit_distance'],
    ['northwindroasters.co', 'northwindroasters.com', 'tld_swap'],
    ['b1ueridgepack.com', 'blueridgepack.com', 'homoglyph'],
    ['rnetro.com', 'metro.com', 'homoglyph'],
    [domainToASCII('blueridgeр' + 'ack.com'), 'blueridgepack.com', 'homoglyph'],
    ['harborlg-billing.com', 'harborlg.com', 'affix'],
    ['payments-harborlg.com', 'harborlg.com', 'affix'],
  ])('%s looks like %s (%s)', (domain, known, method) => {
    expect(lookalike(domain, known)?.method).toBe(method);
  });

  it.each([
    ['blueridgepack.com', 'blueridgepack.com'],
    ['mail.blueridgepack.com', 'blueridgepack.com'],
    ['acme.com', 'zeta.org'],
    ['ab.com', 'ac.com'],
    ['gmail.com', 'blueridgepack.com'],
  ])('%s is not a lookalike of %s', (domain, known) => {
    expect(lookalike(domain, known)).toBeNull();
  });

  it('uses short-label thresholds and transpositions', () => {
    expect(damerauLevenshtein('harbor', 'hrabor')).toBe(1);
    expect(lookalike('acmee.com', 'acme.com')?.method).toBe('edit_distance');
    expect(lookalike('acmeee.com', 'acme.com')).toBeNull();
  });

  it('maps Cyrillic and digit homoglyphs to one skeleton', () => {
    expect(skeleton('рaypal')).toBe(skeleton('paypal'));
    expect(skeleton('paypa1')).toBe(skeleton('paypal'));
    expect(lookalike('acne.org', 'acme.com')?.method).toBe('edit_distance');
    expect(registrableParts('Mail.Blueridge-Pack.COM.')?.ascii).toBe('blueridge-pack.com');
  });

  it('lists lookalikes against known domains and detects free mail', () => {
    expect(findLookalikes(['blueridge-pack.com', 'gmail.com'], ['blueridgepack.com', 'northwindroasters.com'])).toEqual([
      { domain: 'blueridge-pack.com', known: 'blueridgepack.com', method: 'edit_distance' },
    ]);
    expect(isFreeMail('karen.whitfield.ar@gmail.com'.split('@')[1] ?? '')).toBe(true);
    expect(isFreeMail('mail.ru')).toBe(true);
    expect(isFreeMail('blueridgepack.com')).toBe(false);
  });
});

function resolver(overrides: Partial<Record<string, unknown>> = {}): DnsResolver & { calls: string[] } {
  const calls: string[] = [];
  return {
    calls,
    resolveMx: vi.fn(async (name: string) => {
      calls.push(`mx:${name}`);
      if (overrides.mx instanceof Error) throw overrides.mx;
      return [{ exchange: 'mx.example.com', priority: 10 }];
    }),
    resolveTxt: vi.fn(async (name: string) => {
      calls.push(`txt:${name}`);
      if (name.startsWith('_dmarc.')) return [['v=DMARC1; p=reject; rua=mailto:d@example.com']];
      return [['v=spf1 include:_spf.example.com ~all'], ['google-site-verification=x']];
    }),
  };
}

function rdapFetch(status = 200): typeof fetch {
  return vi.fn(async () =>
    new Response(
      JSON.stringify({
        events: [{ eventAction: 'registration', eventDate: '2009-04-17T00:00:00Z' }],
        entities: [{ roles: ['registrar'], vcardArray: ['vcard', [['version', {}, 'text', '4.0'], ['fn', {}, 'text', 'MarkMonitor Inc.']]] }],
      }),
      { status, headers: { 'content-type': 'application/rdap+json' } },
    ),
  );
}

describe('live domain intel driver', () => {
  it('collects MX, SPF, DMARC and RDAP facts and caches them', async () => {
    const dns = resolver();
    const fetchMock = rdapFetch();
    const driver = new LiveDomainIntelDriver({ rdapBaseUrl: 'https://rdap.test/', resolver: dns, fetch: fetchMock, now: () => NOW });
    const facts = await driver.lookup('Mail.BlueRidgePack.com');
    expect(facts).toEqual({
      domain: 'blueridgepack.com',
      unicode_form: null,
      has_mx: true,
      has_spf: true,
      dmarc_policy: 'reject',
      registered_at: '2009-04-17',
      registrar: 'MarkMonitor Inc.',
      rdap_status: 'ok',
      source: 'live',
      checked_at: NOW.toISOString(),
    });
    expect(fetchMock).toHaveBeenCalledWith('https://rdap.test/domain/blueridgepack.com', expect.objectContaining({ redirect: 'follow' }));
    await driver.lookup('blueridgepack.com');
    expect(dns.calls.filter((call) => call.startsWith('mx:'))).toHaveLength(1);
  });

  it('reports missing records, RDAP 404 and DNS failures without blocking', async () => {
    const missing = Object.assign(new Error('nodata'), { code: 'ENODATA' });
    const driver = new LiveDomainIntelDriver({ rdapBaseUrl: 'https://rdap.test', resolver: resolver({ mx: missing }), fetch: rdapFetch(404), now: () => NOW });
    const facts = await driver.lookup('unknown-vendor.com');
    expect(facts).toMatchObject({ has_mx: false, rdap_status: 'not_found', registered_at: null });

    const broken = Object.assign(new Error('servfail'), { code: 'ESERVFAIL' });
    const failing = new LiveDomainIntelDriver({
      rdapBaseUrl: 'https://rdap.test',
      resolver: resolver({ mx: broken }),
      fetch: vi.fn(async () => {
        throw new Error('network down');
      }),
      now: () => NOW,
    });
    expect(await failing.lookup('example.org')).toMatchObject({ has_mx: null, rdap_status: 'error' });
  });

  it('times out slow DNS lookups', async () => {
    const slow: DnsResolver = {
      resolveMx: () => new Promise(() => undefined),
      resolveTxt: () => new Promise(() => undefined),
    };
    const driver = new LiveDomainIntelDriver({ rdapBaseUrl: 'https://rdap.test', resolver: slow, fetch: rdapFetch(), dnsTimeoutMs: 20, now: () => NOW });
    const facts = await driver.lookup('slow.example');
    expect(facts).toMatchObject({ has_mx: null, has_spf: null, dmarc_policy: null, rdap_status: 'ok' });
  });

  it('parses DMARC and RDAP payloads', () => {
    expect(parseDmarc(['v=DMARC1; p=quarantine'])).toBe('quarantine');
    expect(parseDmarc(['v=spf1 -all'])).toBe('absent');
    expect(parseRdap({ events: [], entities: [] })).toEqual({ registered_at: null, registrar: null });
  });
});

describe('fake domain intel driver', () => {
  it('returns relative fixture facts and never invents facts for unknown domains', async () => {
    const driver = new FakeDomainIntelDriver(() => NOW);
    expect(await driver.lookup('blueridge-pack.com')).toMatchObject({ registered_at: '2026-10-08', rdap_status: 'ok', has_spf: false, source: 'fake' });
    expect(await driver.lookup('never-seen.example')).toMatchObject({ rdap_status: 'not_found', has_mx: null, registered_at: null, source: 'fake' });
  });

  it('refuses the fake driver in production', () => {
    expect(() => assertIntelDriverAllowed({ driver: 'fake', appEnv: 'production' })).toThrow(/not allowed in production/);
    expect(() => loadDomainConfig({ NODE_ENV: 'production', DOMAIN_INTEL_DRIVER: 'fake' })).toThrow(/not allowed in production/);
    expect(loadDomainConfig({ NODE_ENV: 'production' }).intelDriver).toBe('live');
    expect(loadDomainConfig({ NODE_ENV: 'test' }).intelDriver).toBe('fake');
  });
});
