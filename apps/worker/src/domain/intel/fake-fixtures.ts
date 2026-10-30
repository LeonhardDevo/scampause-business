import type { DmarcPolicy } from './types.js';

export interface FakeFacts {
  has_mx: boolean;
  has_spf: boolean;
  dmarc_policy: DmarcPolicy;
  registered_days_ago: number;
  registrar: string;
}

const OLD = (days: number, registrar: string, dmarc: DmarcPolicy = 'reject'): FakeFacts => ({
  has_mx: true,
  has_spf: true,
  dmarc_policy: dmarc,
  registered_days_ago: days,
  registrar,
});

const FRESH = (days: number, registrar: string): FakeFacts => ({
  has_mx: true,
  has_spf: false,
  dmarc_policy: 'absent',
  registered_days_ago: days,
  registrar,
});

export const FAKE_DOMAIN_FIXTURES: Readonly<Record<string, FakeFacts>> = Object.freeze({
  'northwindroasters.com': OLD(4120, 'Google LLC'),
  'blueridgepack.com': OLD(6030, 'MarkMonitor Inc.'),
  'blueridge-pack.com': FRESH(15, 'NameSilo, LLC'),
  'andesgreencoffee.com': OLD(3650, 'OVH SAS', 'quarantine'),
  'andesgreen-coffee.com': FRESH(70, 'NameCheap, Inc.'),
  'harborlg.com': OLD(5100, 'Tucows Domains Inc.', 'none'),
  'harbor-lg.com': FRESH(46, 'NameSilo, LLC'),
  'northstarleasing.com': OLD(4800, 'GoDaddy.com, LLC', 'quarantine'),
  'summitoffice.com': OLD(7300, 'Network Solutions, LLC'),
  'cleansweepfs.com': OLD(2900, 'GoDaddy.com, LLC', 'none'),
  'metropowergas.com': OLD(8100, 'MarkMonitor Inc.'),
  'gmail.com': OLD(11000, 'MarkMonitor Inc.', 'none'),
  'outlook.com': OLD(10500, 'MarkMonitor Inc.', 'none'),
  'kedr-partners.ru': OLD(3500, 'RU-CENTER-RU', 'quarantine'),
  'teploservis.ru': OLD(4200, 'RU-CENTER-RU', 'none'),
  'teplo-servis.ru': FRESH(20, 'REGRU-RU'),
  'stroysever.ru': OLD(3900, 'REGRU-RU', 'none'),
  'gorseti.ru': OLD(6200, 'RU-CENTER-RU', 'none'),
  'yandex.ru': OLD(10200, 'RU-CENTER-RU'),
  'mail.ru': OLD(10400, 'RU-CENTER-RU'),
});
