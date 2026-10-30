import { canonicalJson, chainHash, GENESIS_HASH, type ChainEntry } from '../../src/domain/evidence/canonical.js';

export const RU_1C_INVOICE = `ПАО СБЕРБАНК Г. МОСКВА БИК 044525225
Банк получателя Сч. № 30101810400000000225
ИНН 7707083893 КПП 770701001 Сч. № 40702810938000012345
ООО «Стройматериалы Север»
Получатель
Счет на оплату № 512 от 14 октября 2026 г.
Поставщик (Исполнитель): ООО «Стройматериалы Север», ИНН 7707083893, КПП 770701001, 125009, Москва, ул. Тверская, д. 1
Покупатель (Заказчик): ООО «Кедр и Партнёры», ИНН 7714987650
№ Товары (работы, услуги) Кол-во Ед. Цена Сумма
1 Цемент М500 100 мешок 2 483,00 248 300,00
Итого: 248 300,00
В том числе НДС (20%): 41 383,33
Всего к оплате: 248 300,00
Всего наименований 1, на сумму 248 300,00 руб.
Оплатить до 21.10.2026
Генеральный директор Иванов И. И.`;

export const US_INVOICE = `Blue Ridge Packaging Co.
1200 Industrial Blvd, Asheville, NC 28801
Phone: (828) 555-0142

INVOICE
Invoice No. INV-88390
Invoice Date: October 20, 2026
Due Date: November 19, 2026
Bill To: Northwind Coffee Roasters

Description Qty Unit price Amount
Corrugated boxes 12x12x8 2,000 $1.85 $3,700.00
Kraft mailers 1,500 $2.34 $3,510.00
Subtotal $7,210.00
Sales tax $5.00
Total due $7,215.00

Remit payment by ACH:
Beneficiary: Blue Ridge Packaging Company, Inc.
Bank: JPMorgan Chase Bank, N.A.
Routing number (ABA): 021000021
Account number: 000483924417

This e-mail and any attachments are confidential and intended solely for the addressee.`;

export const BEC_EMAIL = `Hi Maria,

Please note that we have changed our bank. Our new bank details are below, please update our records before paying invoice INV-88390.
This is urgent — the payment must go out today. Please don't call, I'm travelling and can't talk.

Beneficiary: Baltic Payments UAB
IBAN: LT09 3250 0247 2001 8839
BIC/SWIFT: REVOLT21
Amount: $18,400.00

Karen Whitfield
AR Manager
+1 828 555 0199`;

export const EPC_PAYLOAD = 'BCD\n002\n1\nSCT\nCOBADEFFXXX\nHarbor Logistics Group\nDE89370400440532013000\nEUR2340.00\n\nHL-7790\nInvoice HL-7790';

export const ST00012_PAYLOAD =
  'ST00012|Name=ООО «ТеплоСервис»|PersonalAcc=40702810938000012345|BankName=ПАО СБЕРБАНК|BIC=044525225|CorrespAcc=30101810400000000225|PayeeINN=7707083893|Sum=18500000|Purpose=Оплата по договору аренды';

let boundaryCounter = 0;

export function eml(options: {
  from?: string;
  replyTo?: string;
  returnPath?: string;
  subject?: string;
  body?: string;
  authResults?: string;
  receivedSpf?: string;
  received?: number;
  attachments?: Array<{ filename: string; contentType: string; content: Buffer | string; raw?: boolean }>;
}): Buffer {
  boundaryCounter += 1;
  const boundary = `BOUNDARY-sp-test-${boundaryCounter}`;
  const headers = [
    ...Array.from({ length: options.received ?? 2 }, (_, index) => `Received: from mx${index}.example.net by mx.northwindroasters.com; Tue, 20 Oct 2026 10:0${index}:00 +0000`),
    ...(options.returnPath ? [`Return-Path: <${options.returnPath}>`] : []),
    ...(options.authResults ? [`Authentication-Results: mx.northwindroasters.com;\r\n ${options.authResults}`] : []),
    ...(options.receivedSpf ? [`Received-SPF: ${options.receivedSpf}`] : []),
    `From: ${options.from ?? '"Karen Whitfield" <billing@blueridge-pack.com>'}`,
    ...(options.replyTo ? [`Reply-To: ${options.replyTo}`] : []),
    'To: maria@northwindroasters.com',
    `Subject: ${options.subject ?? 'Updated bank details'}`,
    'Date: Tue, 20 Oct 2026 10:15:00 +0000',
    'Message-ID: <msg-001@blueridge-pack.com>',
    'MIME-Version: 1.0',
  ];
  const body = options.body ?? BEC_EMAIL;
  if (!options.attachments || options.attachments.length === 0) {
    return Buffer.from([...headers, 'Content-Type: text/plain; charset=utf-8', 'Content-Transfer-Encoding: 8bit', '', body, ''].join('\r\n'), 'utf8');
  }
  const parts: string[] = [`--${boundary}`, 'Content-Type: text/plain; charset=utf-8', 'Content-Transfer-Encoding: 8bit', '', body];
  for (const attachment of options.attachments) {
    const content = Buffer.isBuffer(attachment.content) ? attachment.content : Buffer.from(attachment.content, 'utf8');
    parts.push(`--${boundary}`, `Content-Type: ${attachment.contentType}; name="${attachment.filename}"`, `Content-Disposition: attachment; filename="${attachment.filename}"`);
    if (attachment.raw) parts.push('', content.toString('utf8'));
    else parts.push('Content-Transfer-Encoding: base64', '', content.toString('base64').replace(/(.{76})/g, '$1\r\n'));
  }
  parts.push(`--${boundary}--`, '');
  return Buffer.from([...headers, `Content-Type: multipart/mixed; boundary="${boundary}"`, '', ...parts].join('\r\n'), 'utf8');
}

const MATERIALS = [
  {
    actor_user_id: 7,
    channel: 'web',
    event: 'verification.recorded',
    occurred_at: '2026-10-22T10:15:30.123456Z',
    payload: { account_last4: '4417', method: 'callback_known_number', outcome: 'confirmed', phone_last4: '0142' },
    payment_request_id: 42,
    seq: 1,
    subject_id: 42,
    subject_type: 'payment_request',
    workspace_id: 1,
  },
  {
    actor_user_id: 3,
    channel: 'telegram',
    event: 'approval.approved',
    occurred_at: '2026-10-22T10:20:00.000000Z',
    payload: { risk_level: 'high', slot: 2 },
    payment_request_id: 42,
    seq: 2,
    subject_id: 9,
    subject_type: 'approval',
    workspace_id: 1,
  },
  {
    actor_user_id: 3,
    channel: 'web',
    event: 'request.approved',
    occurred_at: '2026-10-22T10:20:00.000000Z',
    payload: {},
    payment_request_id: 42,
    seq: 3,
    subject_id: 42,
    subject_type: 'payment_request',
    workspace_id: 1,
  },
];

export const HASHES = [
  '271e30a76af16b068108233f3c28782c6a45a3f0dcde05d4f49578d2b4f3e650',
  'ea8327b274a4ac0dc7f0d0830c835f31ee1f18ab41e2a162b3e4a1cfab877d47',
  '10a53e28c6fd822a322d6312d063a295e87f5c76215e718d1ea697a5ae844ef1',
];

export function vectorEntries(): ChainEntry[] {
  let prev = GENESIS_HASH;
  return MATERIALS.map((material) => {
    const text = canonicalJson(material);
    const hash = chainHash(prev, text);
    const entry = { seq: material.seq, prev_hash: prev, hash, material: text };
    prev = hash;
    return entry;
  });
}

