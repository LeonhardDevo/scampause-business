const MASK = '•••';

const EMAIL_RE = /\b([\p{L}\p{N}._%+-]+)@([\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.\p{L}{2,})\b/gu;
const IBAN_RE = /\b([A-Z]{2}\d{2})((?:[ \xa0]?[A-Z0-9]){10,30})\b/g;
const PHONE_RE = /(?<![\p{L}\p{N}])\+\d[\d\s().\xa0-]{7,}\d(?![\p{L}\p{N}])|(?<![\p{L}\p{N}+])\(\d{3}\)[\s.\xa0-]?\d{3}[\s.\xa0-]\d{4}(?![\p{L}\p{N}])|(?<![\p{L}\p{N}+])\d{3}[.\xa0-]\d{3}[.\xa0-]\d{4}(?![\p{L}\p{N}])/gu;
const DIGIT_RUN_RE = /(?<![\p{L}\p{N}.,])\d(?:[ \xa0-]?\d){7,}(?![\p{L}\p{N}]|[.,]\d)/gu;
const THOUSANDS_RE = /^\d{1,3}(?:[ \xa0]\d{3})+$/;
const LABELLED_ID_RE = /(?:ИНН|БИК|КПП|ОГРН|ОГРНИП|ОКПО|EIN|TIN|SWIFT|BIC)\s*[:№#]?\s*$/iu;

function digitsOf(value: string): string {
  return value.replace(/\D/g, '');
}

function last4(value: string): string {
  return digitsOf(value).slice(-4) || value.replace(/[\s\xa0-]/g, '').slice(-4);
}

export function luhnValid(digits: string): boolean {
  let sum = 0;
  let double = false;
  for (let index = digits.length - 1; index >= 0; index--) {
    let value = Number(digits[index]);
    if (double) {
      value *= 2;
      if (value > 9) value -= 9;
    }
    sum += value;
    double = !double;
  }
  return digits.length >= 12 && sum % 10 === 0;
}

export function redactEmails(text: string): string {
  return text.replace(EMAIL_RE, (_match, _local: string, domain: string) => `${MASK}@${domain}`);
}

export function redactIbans(text: string): string {
  return text.replace(IBAN_RE, (match, country: string, rest: string) => {
    const trailing = /(?:[ \xa0][A-Z]+)+$/.exec(rest)?.[0] ?? '';
    const body = trailing === '' ? rest : rest.slice(0, -trailing.length);
    const compact = body.replace(/[\s\xa0]/g, '');
    if (compact.length < 10 || !/\d{2}$/.test(compact)) return match;
    return `${country.slice(0, 2)}${MASK}${compact.slice(-4)}${trailing}`;
  });
}

export function redactPhones(text: string): string {
  return text.replace(PHONE_RE, (match) => {
    const digits = digitsOf(match);
    if (digits.length < 7 || digits.length > 15) return match;
    return `${MASK}${digits.slice(-4)}`;
  });
}

export function redactAccountNumbers(text: string): string {
  return text.replace(DIGIT_RUN_RE, (match: string, offset: number) => {
    if (THOUSANDS_RE.test(match)) return match;
    const before = text.slice(Math.max(0, offset - 12), offset);
    if (LABELLED_ID_RE.test(before)) return match;
    const digits = digitsOf(match);
    if (digits.length < 8) return match;
    if (/^(?:19|20)\d{2}-?\d{2}-?\d{2}$/.test(match) || /^\d{2}-\d{2}-(?:19|20)\d{2}$/.test(match)) return match;
    return luhnValid(digits) && digits.length >= 13 && digits.length <= 19 ? `${MASK}• ${last4(match)}` : `${MASK}${last4(match)}`;
  });
}

export function redactPii(text: string): string {
  if (text === '') return text;
  return redactAccountNumbers(redactPhones(redactIbans(redactEmails(text))));
}
