import { z } from 'zod';
import { errorMessage, withGiveUp, type JobContext } from '../../jobs/types.js';
import { conflictCode, isNoOpConflict } from '../client.js';
import type { DomainMetrics } from '../metrics.js';

export interface NoOp {
  noop: string;
}

export function isNoOp(value: unknown): value is NoOp {
  return Boolean(value) && typeof value === 'object' && typeof (value as { noop?: unknown }).noop === 'string';
}

export async function domainStep<T>(
  ctx: JobContext,
  metrics: DomainMetrics,
  job: string,
  fn: () => Promise<T>,
  onGiveUp: (error: unknown) => Promise<void>,
): Promise<T | NoOp> {
  return withGiveUp<T | NoOp>(
    ctx,
    async () => {
      try {
        return await fn();
      } catch (error) {
        if (isNoOpConflict(error)) {
          ctx.logger.info({ conflict: conflictCode(error) }, 'domain job is a no-op');
          return { noop: conflictCode(error) ?? 'conflict' };
        }
        throw error;
      }
    },
    async (error) => {
      metrics.jobsFailed.inc({ job });
      ctx.logger.error({ job, error: errorMessage(error) }, 'domain job gave up');
      await onGiveUp(error);
    },
  );
}

export const flowDataSchema = z.object({
  payment_request_id: z.number().int().positive(),
  workspace_id: z.number().int().positive(),
  revision: z.number().int().nonnegative(),
  domains: z.array(z.string().min(1).max(253)).max(50).default([]),
});
export type FlowData = z.infer<typeof flowDataSchema>;

export function secondsSince(startedMs: number): number {
  return Math.max(0, (Date.now() - startedMs) / 1000);
}
