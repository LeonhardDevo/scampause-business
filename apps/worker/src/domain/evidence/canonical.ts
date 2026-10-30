import { createHash } from 'node:crypto';

export const GENESIS_HASH = '0'.repeat(64);

export class CanonicalJsonError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'CanonicalJsonError';
  }
}

function compareKeys(a: string, b: string): number {
  return Buffer.compare(Buffer.from(a, 'utf8'), Buffer.from(b, 'utf8'));
}

export function canonicalJson(value: unknown): string {
  if (value === null) return 'null';
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (typeof value === 'number') {
    if (!Number.isInteger(value)) throw new CanonicalJsonError('floats are not allowed in canonical JSON');
    return String(value);
  }
  if (typeof value === 'bigint') return value.toString();
  if (typeof value === 'string') return JSON.stringify(value);
  if (Array.isArray(value)) return `[${value.map((item) => canonicalJson(item)).join(',')}]`;
  if (typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>).filter(([, item]) => item !== undefined);
    entries.sort(([a], [b]) => compareKeys(a, b));
    return `{${entries.map(([key, item]) => `${JSON.stringify(key)}:${canonicalJson(item)}`).join(',')}}`;
  }
  throw new CanonicalJsonError(`unsupported value of type ${typeof value}`);
}

export function chainHash(prevHash: string, material: string): string {
  return createHash('sha256').update(`${prevHash}\n${material}`, 'utf8').digest('hex');
}

export interface ChainEntry {
  seq: number;
  prev_hash: string;
  hash: string;
  material: string;
}

export interface ChainVerification {
  ok: boolean;
  checked: number;
  first_broken_seq: number | null;
  head_hash: string | null;
}

export function verifyChain(entries: readonly ChainEntry[], expectedFirstPrev?: string): ChainVerification {
  let previous: ChainEntry | null = null;
  let checked = 0;
  for (const entry of entries) {
    let ok: boolean;
    try {
      ok = canonicalJson(JSON.parse(entry.material) as unknown) === entry.material;
    } catch {
      ok = false;
    }
    if (ok && previous && entry.prev_hash !== previous.hash) ok = false;
    if (ok && !previous && expectedFirstPrev !== undefined && entry.prev_hash !== expectedFirstPrev) ok = false;
    if (ok && chainHash(entry.prev_hash, entry.material) !== entry.hash) ok = false;
    if (ok && previous && entry.seq !== previous.seq + 1) ok = false;
    if (!ok) return { ok: false, checked, first_broken_seq: entry.seq, head_hash: previous?.hash ?? null };
    checked++;
    previous = entry;
  }
  return { ok: true, checked, first_broken_seq: null, head_hash: previous?.hash ?? null };
}

function materialSeq(material: string): number | null {
  try {
    const parsed = JSON.parse(material) as unknown;
    if (canonicalJson(parsed) !== material || parsed === null || typeof parsed !== 'object') return null;
    const seq = (parsed as Record<string, unknown>).seq;
    return typeof seq === 'number' && Number.isSafeInteger(seq) ? seq : null;
  } catch {
    return null;
  }
}

export function verifyEntries(entries: readonly ChainEntry[]): ChainVerification {
  let previous: ChainEntry | null = null;
  let checked = 0;
  for (const entry of entries) {
    let ok = materialSeq(entry.material) === entry.seq && chainHash(entry.prev_hash, entry.material) === entry.hash;
    if (ok && previous && entry.seq <= previous.seq) ok = false;
    if (ok && previous && entry.seq === previous.seq + 1 && entry.prev_hash !== previous.hash) ok = false;
    if (!ok) return { ok: false, checked, first_broken_seq: entry.seq, head_hash: previous?.hash ?? null };
    checked++;
    previous = entry;
  }
  return { ok: true, checked, first_broken_seq: null, head_hash: previous?.hash ?? null };
}
