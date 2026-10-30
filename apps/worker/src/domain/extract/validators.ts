export const IBAN_LENGTHS: Readonly<Record<string, number>> = Object.freeze({
  AD: 24, AE: 23, AL: 28, AT: 20, AZ: 28, BA: 20, BE: 16, BG: 22, BH: 22, BI: 27, BR: 29, BY: 28, CH: 21, CR: 22, CY: 28,
  CZ: 24, DE: 22, DJ: 27, DK: 18, DO: 28, EE: 20, EG: 29, ES: 24, FI: 18, FK: 18, FO: 18, FR: 27, GB: 22, GE: 22, GI: 23,
  GL: 18, GR: 27, GT: 28, HN: 28, HR: 21, HU: 28, IE: 22, IL: 23, IQ: 23, IS: 26, IT: 27, JO: 30, KW: 30, KZ: 20, LB: 28,
  LC: 32, LI: 21, LT: 20, LU: 20, LV: 21, LY: 25, MC: 27, MD: 24, ME: 22, MK: 19, MN: 20, MR: 27, MT: 31, MU: 30, NI: 28,
  NL: 18, NO: 15, OM: 23, PK: 24, PL: 28, PS: 29, PT: 25, QA: 29, RO: 24, RS: 22, RU: 33, SA: 24, SC: 31, SD: 18, SE: 24,
  SI: 19, SK: 24, SM: 27, SO: 23, ST: 25, SV: 28, TL: 23, TN: 24, TR: 26, UA: 29, VA: 22, VG: 24, XK: 20, YE: 30,
});

const ISO_COUNTRIES = new Set(
  (
    'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD ' +
    'CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF ' +
    'GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN ' +
    'KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA ' +
    'NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI ' +
    'SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI ' +
    'VN VU WF WS XK YE YT ZA ZM ZW'
  ).split(' '),
);

export function isCountryCode(code: string): boolean {
  return ISO_COUNTRIES.has(code.toUpperCase());
}

export function normalizeIban(raw: string): string {
  return raw.toUpperCase().replace(/^IBAN/, '').replace(/[\s\u00A0\-./]/g, '');
}

export function mod97(value: string): number {
  let remainder = 0;
  for (const char of value) {
    const code = char.charCodeAt(0);
    const digits = code >= 65 && code <= 90 ? String(code - 55) : char;
    for (const digit of digits) remainder = (remainder * 10 + (digit.charCodeAt(0) - 48)) % 97;
  }
  return remainder;
}

export function isValidIban(raw: string): boolean {
  const iban = normalizeIban(raw);
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]+$/.test(iban)) return false;
  const length = IBAN_LENGTHS[iban.slice(0, 2)];
  if (length === undefined || iban.length !== length) return false;
  return mod97(`${iban.slice(4)}${iban.slice(0, 4)}`) === 1;
}

export function isValidAba(routing: string): boolean {
  if (!/^\d{9}$/.test(routing)) return false;
  const d = [...routing].map(Number) as [number, number, number, number, number, number, number, number, number];
  const sum = 3 * (d[0] + d[3] + d[6]) + 7 * (d[1] + d[4] + d[7]) + (d[2] + d[5] + d[8]);
  return sum % 10 === 0 && sum > 0;
}

const RU_WEIGHTS = [7, 1, 3];

function ruKey(digits: string): boolean {
  let sum = 0;
  for (let index = 0; index < digits.length; index++) sum += Number(digits[index]) * (RU_WEIGHTS[index % 3] ?? 0);
  return sum % 10 === 0;
}

export function isValidRuAccount(bik: string, account: string): boolean {
  if (!/^\d{9}$/.test(bik) || !/^\d{20}$/.test(account)) return false;
  return ruKey(`${bik.slice(-3)}${account}`);
}

export function isValidRuCorrespondent(bik: string, account: string): boolean {
  if (!/^\d{9}$/.test(bik) || !/^\d{20}$/.test(account)) return false;
  return ruKey(`0${bik.slice(4, 6)}${account}`);
}

function innChecksum(digits: readonly number[], weights: readonly number[]): number {
  let sum = 0;
  for (let index = 0; index < weights.length; index++) sum += (digits[index] ?? 0) * (weights[index] ?? 0);
  return (sum % 11) % 10;
}

export function isValidInn(inn: string): boolean {
  if (!/^(\d{10}|\d{12})$/.test(inn)) return false;
  const digits = [...inn].map(Number);
  if (inn.length === 10) return innChecksum(digits, [2, 4, 10, 3, 5, 9, 4, 6, 8]) === digits[9];
  return (
    innChecksum(digits, [7, 2, 4, 10, 3, 5, 9, 4, 6, 8]) === digits[10] &&
    innChecksum(digits, [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]) === digits[11]
  );
}

export function isValidBicFormat(bic: string): boolean {
  return /^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/.test(bic) && isCountryCode(bic.slice(4, 6));
}

export function normalizeDigits(raw: string): string {
  return raw.replace(/\D/g, '');
}
