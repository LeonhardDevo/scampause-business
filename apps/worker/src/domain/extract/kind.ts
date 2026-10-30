import { categoryPhrases, LEXICON, matchesAny } from '../lexicon.js';
import type { KindSuggestion } from './types.js';

export interface KindInputs {
  text: string;
  hasPaymentQr: boolean;
  hasInvoiceNumber: boolean;
  hasAmount: boolean;
}

export function suggestKind(inputs: KindInputs): KindSuggestion {
  const { text } = inputs;
  if (matchesAny(text, LEXICON.kind.payroll) && matchesAny(text, LEXICON.kind.change)) return 'payroll_change';
  if (matchesAny(text, categoryPhrases('CHANGE_REQUEST_LANGUAGE'))) return 'bank_details_change';
  if (matchesAny(text, LEXICON.kind.wire) && matchesAny(text, categoryPhrases('URGENCY_LANGUAGE'))) return 'urgent_wire';
  if (inputs.hasPaymentQr && !inputs.hasInvoiceNumber) return 'qr_payment';
  if (inputs.hasAmount || inputs.hasInvoiceNumber) return 'invoice';
  return 'other';
}
