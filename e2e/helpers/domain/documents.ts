export type Variant = 'a' | 'b' | 'c' | 'd';

export interface FixtureFile {
  name: string;
  mimeType: string;
  buffer: Buffer;
}

export interface ForeignAccount {
  iban: string;
  bic: string;
  bank: string;
  country: string;
  last4: string;
}

const VARIANTS: readonly Variant[] = ['a', 'b', 'c', 'd'];

const FOREIGN: Readonly<Record<Variant, ForeignAccount>> = {
  a: { iban: 'LT587044060007999129', bic: 'CBVILT2X', bank: 'AB SEB bankas', country: 'LT', last4: '9129' },
  b: { iban: 'CY21002001950000357001234567', bic: 'BCYPCY2N', bank: 'Bank of Cyprus', country: 'CY', last4: '4567' },
  c: { iban: 'DE0750010517540321123456', bic: 'INGDDEFFXXX', bank: 'ING-DiBa AG', country: 'DE', last4: '3456' },
  d: { iban: 'GB29NWBK60161331926819', bic: 'NWBKGB2L', bank: 'NatWest Bank', country: 'GB', last4: '6819' },
};

const OFFSET: Readonly<Record<Variant, number>> = { a: 0, b: 1, c: 2, d: 3 };

export function variantFor(projectName: string): Variant {
  if (projectName.endsWith('ru-dark')) return 'b';
  if (projectName.endsWith('en-dark')) return 'c';
  if (projectName.endsWith('ru-light')) return 'd';
  return 'a';
}

export function allVariants(): readonly Variant[] {
  return VARIANTS;
}

export function foreignAccount(variant: Variant): ForeignAccount {
  return FOREIGN[variant];
}

function isoDate(daysFromToday: number): string {
  const date = new Date(Date.now() + daysFromToday * 86_400_000);
  return date.toISOString().slice(0, 10);
}

function usd(minor: number): string {
  const major = Math.floor(minor / 100).toLocaleString('en-US');
  return `${major}.${String(minor % 100).padStart(2, '0')}`;
}

function escapePdf(value: string): string {
  return value.replace(/[^\x20-\x7E]/g, '?').replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)');
}

export function textPdf(title: string, lines: readonly string[]): Buffer {
  const body = ['BT', '/F1 16 Tf', '56 736 Td', '20 TL', `(${escapePdf(title)}) Tj`, '/F1 10 Tf', '15 TL', 'T*'];
  for (const line of lines.slice(0, 44)) body.push(`(${escapePdf(line)}) Tj T*`);
  body.push('ET');
  const stream = body.join('\n');
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    `<< /Length ${Buffer.byteLength(stream, 'latin1')} >>\nstream\n${stream}\nendstream`,
    `<< /Producer (ScamPause e2e fixtures) /Title (${escapePdf(title)}) >>`,
  ];
  let pdf = '%PDF-1.4\n';
  const offsets: number[] = [];
  objects.forEach((object, index) => {
    offsets.push(Buffer.byteLength(pdf, 'latin1'));
    pdf += `${index + 1} 0 obj\n${object}\nendobj\n`;
  });
  const xref = Buffer.byteLength(pdf, 'latin1');
  pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
  for (const offset of offsets) pdf += `${String(offset).padStart(10, '0')} 00000 n \n`;
  pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R /Info 6 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return Buffer.from(pdf, 'latin1');
}

interface Mail {
  fromName: string;
  fromEmail: string;
  to: string;
  subject: string;
  body: string;
  replyTo?: string;
  messageId: string;
}

export function plainEml(mail: Mail): Buffer {
  const headers = [
    `Return-Path: <${mail.fromEmail}>`,
    'Received: from mail.example.net by mx.northwindroasters.com; ' + new Date().toUTCString(),
    'Authentication-Results: mx.northwindroasters.com; spf=pass; dkim=pass; dmarc=pass',
    `From: "${mail.fromName}" <${mail.fromEmail}>`,
    ...(mail.replyTo ? [`Reply-To: ${mail.replyTo}`] : []),
    `To: ${mail.to}`,
    `Subject: ${mail.subject}`,
    `Date: ${new Date().toUTCString()}`,
    `Message-ID: <${mail.messageId}>`,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
  ];
  return Buffer.from(`${headers.join('\r\n')}\r\n\r\n${mail.body.replace(/\n/g, '\r\n')}\r\n`, 'utf8');
}

export interface BlueRidgeChange {
  file: FixtureFile;
  amountMinor: number;
  invoice: string;
  account: ForeignAccount;
}

export function blueRidgeChangedInvoice(variant: Variant): BlueRidgeChange {
  const offset = OFFSET[variant];
  const amountMinor = 1_895_000 + offset * 1_000;
  const invoice = `88455-${variant.toUpperCase()}`;
  const account = FOREIGN[variant];
  const lines = [
    'EIN 56-1234567',
    '77 Industrial Park Rd, Asheville, NC 28806',
    '',
    `INVOICE ${invoice}`,
    `Invoice date: ${isoDate(0)}`,
    `Due date: ${isoDate(1)}`,
    'Bill to: Northwind Coffee Roasters LLC',
    'Kraft valve bags 2 lb, 9,000 units',
    `Total due: USD ${usd(amountMinor)}`,
    '',
    'Please note our new bank details for this and all future payments.',
    'Beneficiary: BRP Europe Settlements',
    `Bank: ${account.bank}`,
    `BIC: ${account.bic}`,
    `IBAN: ${account.iban}`,
  ];
  return {
    file: { name: `blue-ridge-inv-88455-changed-${variant}.pdf`, mimeType: 'application/pdf', buffer: textPdf('Blue Ridge Packaging Company, Inc.', lines) },
    amountMinor,
    invoice,
    account,
  };
}

export interface AchChange {
  file: FixtureFile;
  amountMinor: number;
  routing: string;
  account: string;
  last4: string;
}

export function harborRemittanceUpdate(variant: Variant): AchChange {
  const offset = OFFSET[variant];
  const amountMinor = 648_000 + offset * 1_000;
  const account = `44719022${31 + offset}`;
  const routing = '026009593';
  const lines = [
    '1 Harbor Plaza, Newark, NJ 07114',
    '',
    `NOTICE OF UPDATED REMITTANCE DETAILS HL-${8120 + offset}`,
    `Invoice HL-${8120 + offset} dated ${isoDate(0)}`,
    'Bill to: Northwind Coffee Roasters LLC',
    'Ocean freight and drayage, container MSKU 4417 220',
    `Total due: USD ${usd(amountMinor)}`,
    `Due date: ${isoDate(10)}`,
    '',
    'Please update our records with our updated remittance details below.',
    'Beneficiary: Harbor Logistics Group',
    'Bank: Bank of America, N.A.',
    `Routing: ${routing}`,
    `Account: ${account}`,
    '',
    'Accounts Receivable, Harbor Logistics Group',
  ];
  return {
    file: { name: `harbor-remittance-update-${variant}.pdf`, mimeType: 'application/pdf', buffer: textPdf('Harbor Logistics Group', lines) },
    amountMinor,
    routing,
    account,
    last4: account.slice(-4),
  };
}

export function jordanDirectDeposit(variant: Variant): AchChange {
  const offset = OFFSET[variant];
  const account = `770045${7321 + offset * 11}`;
  const routing = '121000248';
  const body = [
    'Hi,',
    '',
    'I changed my bank. Please update my direct deposit to the new account below before the next payroll run. It is urgent, I need it working by Friday.',
    '',
    'Bank: Wells Fargo Bank',
    `Routing: ${routing}`,
    `Account: ${account}`,
    '',
    'Thanks,',
    'Jordan Lee',
  ].join('\n');
  return {
    file: {
      name: `jordan-direct-deposit-${variant}.eml`,
      mimeType: 'message/rfc822',
      buffer: plainEml({
        fromName: 'Jordan Lee',
        fromEmail: 'jordan.lee.payroll@gmail.com',
        to: 'payroll@northwindroasters.com',
        subject: 'Direct deposit update',
        body,
        messageId: `jordan-${variant}-${Date.now().toString(36)}@mail.gmail.com`,
      }),
    },
    amountMinor: 0,
    routing,
    account,
    last4: account.slice(-4),
  };
}

export interface KnownInvoice {
  file: FixtureFile;
  amountMinor: number;
  invoice: string;
}

export function andesInvoice(variant: Variant): KnownInvoice {
  const offset = OFFSET[variant];
  const amountMinor = 1_288_000 + offset * 1_000;
  const invoice = `AG-2026-1017-${variant.toUpperCase()}`;
  const major = Math.floor(amountMinor / 100).toLocaleString('en-US');
  const lines = [
    'CIF B12345678',
    'Calle de Alcala 120, 28009 Madrid, Spain',
    '',
    `INVOICE ${invoice}`,
    `Invoice date: ${isoDate(0)}`,
    `Due date: ${isoDate(30)}`,
    'Bill to: Northwind Coffee Roasters LLC',
    'Green coffee, Ethiopia Yirgacheffe Grade 1, 4 bags x 60 kg',
    `Total due: EUR ${major}.00`,
    'Payment terms: 30 days',
    '',
    'Bank: CaixaBank',
    'IBAN: ES79 2100 0813 6101 2345 6789',
    'BIC: CAIXESBBXXX',
  ];
  return {
    file: { name: `andes-ag-2026-1017-${variant}.pdf`, mimeType: 'application/pdf', buffer: textPdf('Andes Green Coffee Importers S.L.', lines) },
    amountMinor,
    invoice,
  };
}
