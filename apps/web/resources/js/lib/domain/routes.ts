import ApprovalController from '@/actions/App/Http/Controllers/Domain/ApprovalController';
import BlockedAccountController from '@/actions/App/Http/Controllers/Domain/BlockedAccountController';
import DataSubjectController from '@/actions/App/Http/Controllers/Domain/DataSubjectController';
import EmployeeResponseController from '@/actions/App/Http/Controllers/Domain/EmployeeResponseController';
import PaymentRequestController from '@/actions/App/Http/Controllers/Domain/PaymentRequestController';
import PaymentRequestWorkflowController from '@/actions/App/Http/Controllers/Domain/PaymentRequestWorkflowController';
import PolicyController from '@/actions/App/Http/Controllers/Domain/PolicyController';
import ReportController from '@/actions/App/Http/Controllers/Domain/ReportController';
import VendorController from '@/actions/App/Http/Controllers/Domain/VendorController';
import VendorMasterDataController from '@/actions/App/Http/Controllers/Domain/VendorMasterDataController';
import type { QueryValue } from '@/lib/endpoints';
import { withQuery } from '@/lib/endpoints';

type Query = Record<string, QueryValue>;

export const sp = {
    requests: {
        index: (query: Query = {}) =>
            withQuery(PaymentRequestController.index().url, query),
        create: () => PaymentRequestController.create(),
        store: () => PaymentRequestController.store(),
        manual: () => PaymentRequestController.manual(),
        show: (id: number, query: Query = {}) =>
            withQuery(PaymentRequestController.show(id).url, query),
        status: (id: number) => PaymentRequestController.status(id),
        update: (id: number) => PaymentRequestController.update(id),
        vendor: (id: number) => PaymentRequestController.vendor(id),
        reassess: (id: number) => PaymentRequestController.reassess(id),
        submit: (id: number) => PaymentRequestWorkflowController.submit(id),
        verify: (id: number) => PaymentRequestWorkflowController.verify(id),
        reject: (id: number) => PaymentRequestWorkflowController.reject(id),
        cancel: (id: number) => PaymentRequestWorkflowController.cancel(id),
        reopen: (id: number) => PaymentRequestWorkflowController.reopen(id),
        revoke: (id: number) => PaymentRequestWorkflowController.revoke(id),
        markPaid: (id: number) => PaymentRequestWorkflowController.markPaid(id),
        reveal: (id: number) => PaymentRequestWorkflowController.reveal(id),
        evidencePacket: (id: number) =>
            PaymentRequestWorkflowController.evidencePacket(id),
        approve: (id: number, approval: number) =>
            ApprovalController.approve({ paymentRequest: id, approval }),
        rejectApproval: (id: number, approval: number) =>
            ApprovalController.reject({ paymentRequest: id, approval }),
        reassign: (id: number, approval: number) =>
            ApprovalController.reassign({ paymentRequest: id, approval }),
    },
    approvals: {
        index: (query: Query = {}) =>
            withQuery(ApprovalController.index().url, query),
    },
    vendors: {
        index: (query: Query = {}) =>
            withQuery(VendorController.index().url, query),
        create: () => VendorController.create(),
        store: () => VendorController.store(),
        show: (id: number) => VendorController.show(id),
        edit: (id: number) => VendorController.edit(id),
        update: (id: number) => VendorController.update(id),
        destroy: (id: number) => VendorController.destroy(id),
        dataSubject: (id: number) => DataSubjectController(id),
        contacts: {
            store: (vendor: number) =>
                VendorMasterDataController.storeContact(vendor),
            update: (vendor: number, contact: number) =>
                VendorMasterDataController.updateContact({ vendor, contact }),
            destroy: (vendor: number, contact: number) =>
                VendorMasterDataController.destroyContact({ vendor, contact }),
            verify: (vendor: number, contact: number) =>
                VendorMasterDataController.verifyContact({ vendor, contact }),
        },
        domains: {
            store: (vendor: number) =>
                VendorMasterDataController.storeDomain(vendor),
            destroy: (vendor: number, vendorDomain: number) =>
                VendorMasterDataController.destroyDomain({
                    vendor,
                    vendorDomain,
                }),
        },
        accounts: {
            store: (vendor: number) =>
                VendorMasterDataController.storeAccount(vendor),
            verify: (vendor: number, account: number) =>
                VendorMasterDataController.verifyAccount({ vendor, account }),
            retire: (vendor: number, account: number) =>
                VendorMasterDataController.retireAccount({ vendor, account }),
            reveal: (vendor: number, account: number) =>
                VendorMasterDataController.revealAccount({ vendor, account }),
        },
    },
    blocked: {
        index: (query: Query = {}) =>
            withQuery(BlockedAccountController.index().url, query),
        store: () => BlockedAccountController.store(),
        destroy: (id: number) => BlockedAccountController.destroy(id),
    },
    policies: {
        index: (query: Query = {}) =>
            withQuery(PolicyController.index().url, query),
        storeRule: () => PolicyController.storeRule(),
        updateRule: (id: number) => PolicyController.updateRule(id),
        destroyRule: (id: number) => PolicyController.destroyRule(id),
        reorder: () => PolicyController.reorder(),
        limits: (user: number) => PolicyController.limits(user),
        settings: () => PolicyController.settings(),
        storeRate: () => PolicyController.storeRate(),
        destroyRate: (id: number) => PolicyController.destroyRate(id),
        simulate: () => PolicyController.simulate(),
    },
    reports: {
        index: (query: Query = {}) =>
            withQuery(ReportController.index().url, query),
        storeExport: () => ReportController.storeExport(),
        retryExport: (id: number) => ReportController.retryExport(id),
        verifyChain: () => ReportController.verifyChain(),
    },
    employee: {
        store: (token: string) => EmployeeResponseController.store(token),
    },
} as const;

export function requestPath(id: number): string {
    return PaymentRequestController.show(id).url;
}
