import { randomUUID } from 'node:crypto';
import type { Redis } from 'ioredis';

export interface DomainLocks {
  acquire(key: string, ttlMs: number): Promise<string | null>;
  release(key: string, token: string): Promise<void>;
  acquireSlot(key: string, limit: number, ttlMs: number): Promise<string | null>;
  releaseSlot(key: string, token: string): Promise<void>;
}

const RELEASE_SCRIPT = `if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end`;
const SLOT_SCRIPT = `
redis.call('zremrangebyscore', KEYS[1], '-inf', ARGV[1])
if redis.call('zcard', KEYS[1]) < tonumber(ARGV[2]) then
  redis.call('zadd', KEYS[1], ARGV[3], ARGV[4])
  redis.call('pexpire', KEYS[1], ARGV[5])
  return 1
end
return 0`;

export class RedisDomainLocks implements DomainLocks {
  constructor(
    private readonly redis: Redis,
    private readonly prefix: string,
  ) {}

  async acquire(key: string, ttlMs: number): Promise<string | null> {
    const token = randomUUID();
    const result = await this.redis.set(`${this.prefix}${key}`, token, 'PX', ttlMs, 'NX');
    return result === 'OK' ? token : null;
  }

  async release(key: string, token: string): Promise<void> {
    await this.redis.eval(RELEASE_SCRIPT, 1, `${this.prefix}${key}`, token);
  }

  async acquireSlot(key: string, limit: number, ttlMs: number): Promise<string | null> {
    const token = randomUUID();
    const now = Date.now();
    const result = await this.redis.eval(SLOT_SCRIPT, 1, `${this.prefix}${key}`, String(now), String(limit), String(now + ttlMs), token, String(ttlMs));
    return result === 1 ? token : null;
  }

  async releaseSlot(key: string, token: string): Promise<void> {
    await this.redis.zrem(`${this.prefix}${key}`, token);
  }
}

export class MemoryDomainLocks implements DomainLocks {
  private readonly locks = new Map<string, { token: string; expiresAt: number }>();
  private readonly slots = new Map<string, Map<string, number>>();

  async acquire(key: string, ttlMs: number): Promise<string | null> {
    const now = Date.now();
    const existing = this.locks.get(key);
    if (existing && existing.expiresAt > now) return null;
    const token = randomUUID();
    this.locks.set(key, { token, expiresAt: now + ttlMs });
    return token;
  }

  async release(key: string, token: string): Promise<void> {
    if (this.locks.get(key)?.token === token) this.locks.delete(key);
  }

  async acquireSlot(key: string, limit: number, ttlMs: number): Promise<string | null> {
    const now = Date.now();
    const slots = this.slots.get(key) ?? new Map<string, number>();
    for (const [token, expiresAt] of slots) if (expiresAt <= now) slots.delete(token);
    if (slots.size >= limit) return null;
    const token = randomUUID();
    slots.set(token, now + ttlMs);
    this.slots.set(key, slots);
    return token;
  }

  async releaseSlot(key: string, token: string): Promise<void> {
    this.slots.get(key)?.delete(token);
  }
}
