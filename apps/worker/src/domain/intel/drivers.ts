import { promises as dnsPromises } from 'node:dns';
import { registrableParts } from './lookalike.js';
import { FAKE_DOMAIN_FIXTURES } from './fake-fixtures.js';
import type { DmarcPolicy, DomainFacts, DomainIntelDriver, RdapStatus } from './types.js';

export interface DnsResolver {
  resolveMx(hostname: string): Promise<Array<{ exchange: string; priority: number }>>;
  resolveTxt(hostname: string): Promise<string[][]>;
}

export interface LiveDriverOptions {
  rdapBaseUrl: string;
  dnsTimeoutMs?: number;
  rdapTimeoutMs?: number;
  cacheTtlMs?: number;
  resolver?: DnsResolver;
  fetch?: typeof fetch;
  now?: () => Date;
}

const DNS_MISSING_CODES = new Set(['ENODATA', 'ENOTFOUND', 'ENODOMAIN', 'NXDOMAIN']);

class TimeoutError extends Error {
  constructor(what: string) {
    super(`${what} timed out`);
    this.name = 'TimeoutError';
  }
}

async function withTimeout<T>(promise: Promise<T>, ms: number, what: string): Promise<T> {
  let timer: NodeJS.Timeout | undefined;
  try {
    return await Promise.race([
      promise,
      new Promise<never>((_, reject) => {
        timer = setTimeout(() => reject(new TimeoutError(what)), ms);
        timer.unref();
      }),
    ]);
  } finally {
    if (timer) clearTimeout(timer);
  }
}

function isMissing(error: unknown): boolean {
  const code = (error as { code?: string } | null)?.code;
  return typeof code === 'string' && DNS_MISSING_CODES.has(code);
}

export function parseDmarc(records: readonly string[]): DmarcPolicy {
  for (const record of records) {
    if (!/^v=DMARC1/i.test(record.trim())) continue;
    const policy = /(?:^|;)\s*p\s*=\s*(none|quarantine|reject)/i.exec(record)?.[1]?.toLowerCase();
    if (policy === 'none' || policy === 'quarantine' || policy === 'reject') return policy;
  }
  return 'absent';
}

interface RdapEntity {
  roles?: string[];
  vcardArray?: [string, Array<[string, unknown, string, unknown]>];
  publicIds?: Array<{ identifier?: string }>;
}

interface RdapResponse {
  events?: Array<{ eventAction?: string; eventDate?: string }>;
  entities?: RdapEntity[];
}

export function parseRdap(body: RdapResponse): { registered_at: string | null; registrar: string | null } {
  const registration = body.events?.find((event) => event.eventAction === 'registration')?.eventDate ?? null;
  const date = registration ? new Date(registration) : null;
  const registeredAt = date && !Number.isNaN(date.getTime()) ? date.toISOString().slice(0, 10) : null;
  const entity = body.entities?.find((item) => item.roles?.includes('registrar'));
  const fn = entity?.vcardArray?.[1]?.find((entry) => entry[0] === 'fn')?.[3];
  return { registered_at: registeredAt, registrar: typeof fn === 'string' && fn.trim() !== '' ? fn.trim().slice(0, 255) : null };
}

export class LiveDomainIntelDriver implements DomainIntelDriver {
  readonly source = 'live' as const;
  private readonly cache = new Map<string, { facts: DomainFacts; expiresAt: number }>();
  private readonly resolver: DnsResolver;
  private readonly fetchFn: typeof fetch;
  private readonly now: () => Date;

  constructor(private readonly options: LiveDriverOptions) {
    this.resolver = options.resolver ?? dnsPromises;
    this.fetchFn = options.fetch ?? fetch;
    this.now = options.now ?? (() => new Date());
  }

  async lookup(raw: string): Promise<DomainFacts> {
    const parts = registrableParts(raw);
    const domain = parts?.ascii ?? raw.toLowerCase();
    const cached = this.cache.get(domain);
    if (cached && cached.expiresAt > this.now().getTime()) return cached.facts;
    const [mx, spf, dmarc, rdap] = await Promise.all([this.mx(domain), this.spf(domain), this.dmarc(domain), this.rdap(domain)]);
    const facts: DomainFacts = {
      domain,
      unicode_form: parts && parts.unicode !== parts.ascii ? parts.unicode : null,
      has_mx: mx,
      has_spf: spf,
      dmarc_policy: dmarc,
      registered_at: rdap.registered_at,
      registrar: rdap.registrar,
      rdap_status: rdap.status,
      source: 'live',
      checked_at: this.now().toISOString(),
    };
    const ttl = rdap.status === 'error' ? 3_600_000 : (this.options.cacheTtlMs ?? 3_600_000);
    this.cache.set(domain, { facts, expiresAt: this.now().getTime() + ttl });
    return facts;
  }

  private async mx(domain: string): Promise<boolean | null> {
    try {
      const records = await withTimeout(this.resolver.resolveMx(domain), this.options.dnsTimeoutMs ?? 3000, 'MX lookup');
      return records.some((record) => record.exchange !== '' && record.exchange !== '.');
    } catch (error) {
      return isMissing(error) ? false : null;
    }
  }

  private async txt(name: string): Promise<string[] | null> {
    try {
      const records = await withTimeout(this.resolver.resolveTxt(name), this.options.dnsTimeoutMs ?? 3000, 'TXT lookup');
      return records.map((chunks) => chunks.join(''));
    } catch (error) {
      return isMissing(error) ? [] : null;
    }
  }

  private async spf(domain: string): Promise<boolean | null> {
    const records = await this.txt(domain);
    return records === null ? null : records.some((record) => /^v=spf1(?:\s|$)/i.test(record.trim()));
  }

  private async dmarc(domain: string): Promise<DmarcPolicy | null> {
    const records = await this.txt(`_dmarc.${domain}`);
    return records === null ? null : parseDmarc(records);
  }

  private async rdap(domain: string): Promise<{ status: RdapStatus; registered_at: string | null; registrar: string | null }> {
    const url = `${this.options.rdapBaseUrl.replace(/\/+$/, '')}/domain/${encodeURIComponent(domain)}`;
    try {
      const response = await this.fetchFn(url, {
        headers: { Accept: 'application/rdap+json, application/json' },
        redirect: 'follow',
        signal: AbortSignal.timeout(this.options.rdapTimeoutMs ?? 5000),
      });
      if (response.status === 404) return { status: 'not_found', registered_at: null, registrar: null };
      if (!response.ok) return { status: 'error', registered_at: null, registrar: null };
      const parsed = parseRdap((await response.json()) as RdapResponse);
      return { status: 'ok', ...parsed };
    } catch {
      return { status: 'error', registered_at: null, registrar: null };
    }
  }
}

export class FakeDomainIntelDriver implements DomainIntelDriver {
  readonly source = 'fake' as const;

  constructor(private readonly now: () => Date = () => new Date()) {}

  async lookup(raw: string): Promise<DomainFacts> {
    const parts = registrableParts(raw);
    const domain = parts?.ascii ?? raw.toLowerCase();
    const fixture = FAKE_DOMAIN_FIXTURES[domain];
    const now = this.now();
    const base: DomainFacts = {
      domain,
      unicode_form: parts && parts.unicode !== parts.ascii ? parts.unicode : null,
      has_mx: null,
      has_spf: null,
      dmarc_policy: null,
      registered_at: null,
      registrar: null,
      rdap_status: 'not_found',
      source: 'fake',
      checked_at: now.toISOString(),
    };
    if (!fixture) return base;
    const registered = new Date(now.getTime() - fixture.registered_days_ago * 86_400_000).toISOString().slice(0, 10);
    return {
      ...base,
      has_mx: fixture.has_mx,
      has_spf: fixture.has_spf,
      dmarc_policy: fixture.dmarc_policy,
      registered_at: registered,
      registrar: fixture.registrar,
      rdap_status: 'ok',
    };
  }
}

export interface IntelDriverConfig {
  driver: 'fake' | 'live';
  appEnv: string;
}

export function assertIntelDriverAllowed(config: IntelDriverConfig): void {
  if (config.driver === 'fake' && config.appEnv === 'production') {
    throw new Error('DOMAIN_INTEL_DRIVER=fake is not allowed in production');
  }
}
