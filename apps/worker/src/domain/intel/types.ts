export type RdapStatus = 'ok' | 'not_found' | 'error' | 'skipped';
export type DmarcPolicy = 'none' | 'quarantine' | 'reject' | 'absent';
export type IntelSource = 'live' | 'fake';

export interface DomainFacts {
  domain: string;
  unicode_form: string | null;
  has_mx: boolean | null;
  has_spf: boolean | null;
  dmarc_policy: DmarcPolicy | null;
  registered_at: string | null;
  registrar: string | null;
  rdap_status: RdapStatus;
  source: IntelSource;
  checked_at: string;
}

export interface DomainIntelDriver {
  readonly source: IntelSource;
  lookup(domain: string): Promise<DomainFacts>;
}
