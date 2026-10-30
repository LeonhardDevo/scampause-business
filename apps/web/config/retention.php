<?php

declare(strict_types=1);

use App\Domain\Privacy\RetentionPurger;

return [
    'defaults' => [
        'ai_queries' => (int) env('RETENTION_AI_QUERIES_DAYS', 180),
        'audit_logs' => (int) env('RETENTION_AUDIT_LOGS_DAYS', 365),
        'deliveries' => (int) env('RETENTION_DELIVERIES_DAYS', 90),
        'webhook_events' => (int) env('RETENTION_WEBHOOK_EVENTS_DAYS', 30),
        'outbox_dispatched' => (int) env('RETENTION_OUTBOX_DAYS', 7),
        'failed_jobs' => (int) env('RETENTION_FAILED_JOBS_DAYS', 30),
        'notifications' => (int) env('RETENTION_NOTIFICATIONS_DAYS', 180),

        'deleted_documents' => 0,

        'data_exports' => max(1, (int) ceil(((int) env('PRIVACY_EXPORT_LINK_HOURS', 24)) / 24)),

        'scampause_files' => 2555,
        'scampause_evidence' => (int) env('RETENTION_SCAMPAUSE_EVIDENCE_DAYS', 2555),
        'scampause_exports' => 30,
        'scampause_notifications' => 365,
        'scampause_domain_intel' => 90,
    ],

    'account_deletion_grace' => (int) env('PRIVACY_USER_DELETION_GRACE_DAYS', 14),
    'workspace_deletion_grace' => (int) env('PRIVACY_WORKSPACE_DELETION_GRACE_DAYS', 30),
    'export_link_hours' => (int) env('PRIVACY_EXPORT_LINK_HOURS', 24),

    'overridable' => [
        'ai_queries' => ['min' => 7, 'max' => 730],
        'audit_logs' => ['min' => 30, 'max' => 2555],
        'scampause_files' => ['min' => 30, 'max' => 2555],
        'scampause_evidence' => ['min' => 1825, 'max' => 3650],
        'scampause_exports' => ['min' => 1, 'max' => 90],
        'scampause_notifications' => ['min' => 90, 'max' => 730],
    ],

    'plan_max' => [
        'free' => ['ai_queries' => 180, 'audit_logs' => 365, 'scampause_files' => 90],
        'starter' => ['scampause_files' => 730],
        'pro' => ['scampause_files' => 2555],
        'business' => ['scampause_files' => 2555],
    ],

    'contributors' => [
        RetentionPurger::class,
    ],
];
