import type { JobDeps } from '../../jobs/deps.js';
import { permanent, type HandlerMap, type JobHandler } from '../../jobs/types.js';
import type { DomainJobServices } from './deps.js';
import { QUEUES } from '../../queues/names.js';
import {
  APPROVAL_JOBS,
  createApprovalDispatchHandler,
  createApprovalEmailHandler,
  createApprovalTelegramHandler,
  createEscalationHandler,
  createExpiryHandler,
  createReminderHandler,
  createSweepHandler,
} from './approvals.js';
import { AUDIT_JOBS, createChainVerifyHandler, createEvidencePacketHandler, createJournalHandler } from './audit.js';
import { createBankCompareHandler, createDomainCheckHandler, createIntakeExtractHandler, createScoreHandler, INTAKE_JOBS } from './intake.js';

export type DomainQueue = typeof QUEUES.intake | typeof QUEUES.domainIntel | typeof QUEUES.approvals | typeof QUEUES.audit;

function withDomain(deps: JobDeps, factory: (services: DomainJobServices) => JobHandler): JobHandler {
  let handler: JobHandler | null = null;
  return async (data, ctx) => {
    if (!deps.domain) throw permanent('domain services are not configured');
    handler ??= factory(deps.domain);
    return handler(data, ctx);
  };
}

export function createDomainHandlers(deps: JobDeps): Record<DomainQueue, HandlerMap> {
  return {
    [QUEUES.intake]: {
      [INTAKE_JOBS.received]: withDomain(deps, createIntakeExtractHandler),
      [INTAKE_JOBS.bankCompare]: withDomain(deps, createBankCompareHandler),
      [INTAKE_JOBS.score]: withDomain(deps, createScoreHandler),
    },
    [QUEUES.domainIntel]: {
      [INTAKE_JOBS.domainCheck]: withDomain(deps, createDomainCheckHandler),
    },
    [QUEUES.approvals]: {
      [APPROVAL_JOBS.requested]: createApprovalDispatchHandler(deps),
      [APPROVAL_JOBS.dispatchTelegram]: createApprovalTelegramHandler(deps),
      [APPROVAL_JOBS.dispatchEmail]: createApprovalEmailHandler(deps),
      [APPROVAL_JOBS.reminder]: createReminderHandler(deps),
      [APPROVAL_JOBS.escalation]: createEscalationHandler(deps),
      [APPROVAL_JOBS.expiry]: createExpiryHandler(deps),
      [APPROVAL_JOBS.sweep]: createSweepHandler(deps),
    },
    [QUEUES.audit]: {
      [AUDIT_JOBS.packet]: createEvidencePacketHandler(deps),
      [AUDIT_JOBS.journal]: createJournalHandler(deps),
      [AUDIT_JOBS.chainVerify]: createChainVerifyHandler(deps),
    },
  };
}

export { registerDomainFlowsAndSchedulers, BullIntakeFlows, intakeFlowDefinition } from './flows.js';
export type { DomainJobServices };
