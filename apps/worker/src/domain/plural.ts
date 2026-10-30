export type PluralForms = { one: string; few: string; many: string };

const RU_RULES = new Intl.PluralRules('ru');

export function ruPluralCategory(count: number): keyof PluralForms {
  const category = RU_RULES.select(count);
  if (category === 'one' || category === 'few') return category;
  return 'many';
}

export function ruPlural(count: number, forms: PluralForms): string {
  return forms[ruPluralCategory(count)];
}

export function enPlural(count: number, one: string, other: string): string {
  return count === 1 ? one : other;
}
