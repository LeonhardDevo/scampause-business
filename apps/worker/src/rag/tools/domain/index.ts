import type { ToolRegistry } from '../types.js';
import { EvaluateApprovalPolicyTool } from './policy.js';
import { GetPaymentRequestTool, ListMyPendingApprovalsTool } from './requests.js';
import { FindVendorTool, GetVendorBankHistoryTool, GetVendorOfficialContactsTool } from './vendors.js';

export const DOMAIN_TOOL_NAMES = [
  'evaluate_approval_policy',
  'find_vendor',
  'get_vendor_official_contacts',
  'get_vendor_bank_history',
  'get_payment_request',
  'list_my_pending_approvals',
] as const;

export function registerDomainTools(registry: ToolRegistry): void {
  registry.register(new EvaluateApprovalPolicyTool());
  registry.register(new FindVendorTool());
  registry.register(new GetVendorOfficialContactsTool());
  registry.register(new GetVendorBankHistoryTool());
  registry.register(new GetPaymentRequestTool());
  registry.register(new ListMyPendingApprovalsTool());
}

export { EvaluateApprovalPolicyTool, FindVendorTool, GetVendorOfficialContactsTool, GetVendorBankHistoryTool, GetPaymentRequestTool, ListMyPendingApprovalsTool };
