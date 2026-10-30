import { parsePhoneNumberFromString } from 'libphonenumber-js/max';
import type { SourcePool } from '../answer/sources.js';

const PLACEHOLDER_RE = /\[\[(PHONE|PERSON)_(\d{1,3})\]\]/g;

export function formatPhone(e164: string): string {
  const parsed = parsePhoneNumberFromString(e164);
  return parsed ? parsed.formatInternational() : e164;
}

export function phoneDigits(value: string): string {
  return value.replace(/\D/g, '');
}

export class PlaceholderRegistry {
  private readonly phones = new Map<string, string>();
  private readonly persons = new Map<string, string>();
  private readonly restoredPhones = new Set<string>();

  phone(e164: string): string {
    for (const [key, value] of this.phones) if (value === e164) return key;
    const key = `[[PHONE_${this.phones.size + 1}]]`;
    this.phones.set(key, e164);
    return key;
  }

  person(name: string): string {
    const clean = name.trim();
    for (const [key, value] of this.persons) if (value === clean) return key;
    const key = `[[PERSON_${this.persons.size + 1}]]`;
    this.persons.set(key, clean);
    return key;
  }

  get size(): number {
    return this.phones.size + this.persons.size;
  }

  restore(text: string): string {
    return text.replace(PLACEHOLDER_RE, (match) => {
      const phone = this.phones.get(match);
      if (phone) {
        this.restoredPhones.add(phoneDigits(phone));
        return formatPhone(phone);
      }
      return this.persons.get(match) ?? '';
    });
  }

  issuedPhoneDigits(): Set<string> {
    return new Set([...this.phones.values()].map(phoneDigits));
  }

  restoredPhoneDigits(): Set<string> {
    return new Set(this.restoredPhones);
  }
}

const registries = new WeakMap<SourcePool, PlaceholderRegistry>();

export function placeholdersFor(pool: SourcePool): PlaceholderRegistry {
  let registry = registries.get(pool);
  if (!registry) {
    registry = new PlaceholderRegistry();
    registries.set(pool, registry);
  }
  return registry;
}

export function untrusted(value: string | null | undefined): { untrusted_text: string } | null {
  return value === null || value === undefined ? null : { untrusted_text: value };
}
