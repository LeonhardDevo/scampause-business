import { describe, expect, it } from 'vitest';
import { isConcurrencyConflict } from '../../src/rag/ingest/service.js';

describe('ingest concurrency conflicts', () => {
  it('treats deadlocks and serialization failures as retryable', () => {
    expect(isConcurrencyConflict(Object.assign(new Error('deadlock detected'), { code: '40P01' }))).toBe(true);
    expect(isConcurrencyConflict(Object.assign(new Error('could not serialize access'), { code: '40001' }))).toBe(true);
  });

  it('keeps other failures permanent', () => {
    expect(isConcurrencyConflict(Object.assign(new Error('unique violation'), { code: '23505' }))).toBe(false);
    expect(isConcurrencyConflict(new Error('boom'))).toBe(false);
    expect(isConcurrencyConflict(null)).toBe(false);
  });
});
