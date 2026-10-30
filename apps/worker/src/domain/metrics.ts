import { Counter, Histogram, Registry } from 'prom-client';

export class DomainMetrics {
  readonly pipelineDuration: Histogram<'stage'>;
  readonly jobsFailed: Counter<'job'>;
  readonly approvalNotifications: Counter<'channel' | 'status'>;

  constructor(registry: Registry = new Registry()) {
    this.pipelineDuration = new Histogram({
      name: 'scampause_pipeline_duration_seconds',
      help: 'Duration of intake pipeline stages in seconds.',
      labelNames: ['stage'],
      buckets: [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 180],
      registers: [registry],
    });
    this.jobsFailed = new Counter({
      name: 'scampause_jobs_failed_total',
      help: 'Domain jobs that failed permanently.',
      labelNames: ['job'],
      registers: [registry],
    });
    this.approvalNotifications = new Counter({
      name: 'scampause_approval_notifications_total',
      help: 'Approval notifications by channel and delivery status.',
      labelNames: ['channel', 'status'],
      registers: [registry],
    });
  }
}
