import { FlowProducer, type ConnectionOptions, type Queue } from 'bullmq';
import { DEFAULT_JOB_OPTIONS } from '../../queues/defaults.js';
import { QUEUES, type QueueName } from '../../queues/names.js';
import { APPROVAL_JOBS } from './approvals.js';
import { AUDIT_JOBS } from './audit.js';
import type { IntakeFlowSpec, IntakeFlowStarter } from './deps.js';
import { INTAKE_JOBS } from './intake.js';
import type { FlowData } from './support.js';

export const APPROVALS_SWEEP_SCHEDULER = 'approvals-sweep';
export const CHAIN_VERIFY_SCHEDULER = 'audit-chain-verify';
export const APPROVALS_SWEEP_EVERY_MS = 300_000;
export const CHAIN_VERIFY_CRON = '0 3 * * *';

export interface FlowProducerLike {
  add(flow: Parameters<FlowProducer['add']>[0]): Promise<unknown>;
  close(): Promise<void>;
}

export function intakeFlowJobIds(spec: Pick<IntakeFlowSpec, 'paymentRequestId' | 'revision'>): { score: string; domain: string; bank: string } {
  return {
    score: `score:${spec.paymentRequestId}:${spec.revision}`,
    domain: `domain:${spec.paymentRequestId}:${spec.revision}`,
    bank: `bank:${spec.paymentRequestId}:${spec.revision}`,
  };
}

export function intakeFlowDefinition(spec: IntakeFlowSpec): Parameters<FlowProducer['add']>[0] {
  const data: FlowData = {
    payment_request_id: spec.paymentRequestId,
    workspace_id: spec.workspaceId,
    revision: spec.revision,
    domains: [...new Set(spec.domains.map((domain) => domain.toLowerCase()))].slice(0, 50),
  };
  const ids = intakeFlowJobIds(spec);
  return {
    name: INTAKE_JOBS.score,
    queueName: QUEUES.intake,
    data,
    opts: { ...DEFAULT_JOB_OPTIONS, jobId: ids.score },
    children: [
      { name: INTAKE_JOBS.domainCheck, queueName: QUEUES.domainIntel, data, opts: { ...DEFAULT_JOB_OPTIONS, jobId: ids.domain, ignoreDependencyOnFailure: true } },
      { name: INTAKE_JOBS.bankCompare, queueName: QUEUES.intake, data, opts: { ...DEFAULT_JOB_OPTIONS, jobId: ids.bank, failParentOnFailure: true } },
    ],
  };
}

export class BullIntakeFlows implements IntakeFlowStarter {
  constructor(private readonly producer: FlowProducerLike) {}

  async startIntakeFlow(spec: IntakeFlowSpec): Promise<void> {
    await this.producer.add(intakeFlowDefinition(spec));
  }
}

export interface DomainRuntime {
  flows: BullIntakeFlows;
  startSchedulers(): Promise<void>;
  close(): Promise<void>;
}

export async function upsertDomainSchedulers(queues: ReadonlyMap<QueueName, Queue>): Promise<void> {
  const approvals = queues.get(QUEUES.approvals);
  const audit = queues.get(QUEUES.audit);
  if (approvals) {
    await approvals.upsertJobScheduler(APPROVALS_SWEEP_SCHEDULER, { every: APPROVALS_SWEEP_EVERY_MS }, { name: APPROVAL_JOBS.sweep, data: {}, opts: { attempts: 1, removeOnComplete: 100, removeOnFail: 500 } });
  }
  if (audit) {
    await audit.upsertJobScheduler(CHAIN_VERIFY_SCHEDULER, { pattern: CHAIN_VERIFY_CRON, tz: 'UTC' }, { name: AUDIT_JOBS.chainVerify, data: {}, opts: { attempts: 1, removeOnComplete: 100, removeOnFail: 500 } });
  }
}

export function registerDomainFlowsAndSchedulers(options: {
  connection: ConnectionOptions;
  prefix: string;
  queues: ReadonlyMap<QueueName, Queue>;
}): DomainRuntime {
  const producer = new FlowProducer({ connection: options.connection, prefix: options.prefix });
  return {
    flows: new BullIntakeFlows(producer),
    startSchedulers: () => upsertDomainSchedulers(options.queues),
    close: () => producer.close(),
  };
}
