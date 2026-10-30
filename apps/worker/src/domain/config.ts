import { z } from 'zod';
import { assertIntelDriverAllowed } from './intel/drivers.js';

export interface DomainConfig {
  appEnv: string;
  intelDriver: 'fake' | 'live';
  rdapBaseUrl: string;
  extractMaxTextChars: number;
  tesseractPath: string;
  tessdataPrefix: string | undefined;
  ocrLanguages: string;
  pageTimeoutMs: number;
  publicWebUrl: string;
  intakeWorkspaceConcurrency: number;
}

const optional = z
  .string()
  .optional()
  .transform((value) => (value === undefined || value.trim() === '' ? undefined : value.trim()));

const schema = z.object({
  APP_ENV: optional,
  NODE_ENV: optional,
  DOMAIN_INTEL_DRIVER: z.enum(['fake', 'live']).optional(),
  RDAP_BASE_URL: z.string().trim().url().default('https://rdap.org'),
  EXTRACT_MAX_TEXT_CHARS: z.coerce.number().int().min(1000).max(2_000_000).default(200_000),
  TESSERACT_PATH: optional,
  TESSERACT_CMD: optional,
  TESSDATA_PREFIX: optional,
  OCR_LANGUAGES: optional,
  PARSE_PAGE_TIMEOUT_S: z.coerce.number().int().min(1).max(600).default(30),
  PUBLIC_WEB_URL: optional,
  WEB_PUBLIC_URL: optional,
  APP_URL: optional,
  INTAKE_WORKSPACE_CONCURRENCY: z.coerce.number().int().min(1).max(16).default(2),
});

export function loadDomainConfig(source: Record<string, string | undefined> = process.env, fallbackPublicUrl = 'http://127.0.0.1:8300'): DomainConfig {
  const cleaned: Record<string, string> = {};
  for (const [key, value] of Object.entries(source)) if (value !== undefined && value.trim() !== '') cleaned[key] = value;
  const env = schema.parse(cleaned);
  const appEnv = env.APP_ENV ?? (env.NODE_ENV === 'production' ? 'production' : (env.NODE_ENV ?? 'local'));
  const intelDriver = env.DOMAIN_INTEL_DRIVER ?? (appEnv === 'production' ? 'live' : 'fake');
  assertIntelDriverAllowed({ driver: intelDriver, appEnv });
  return Object.freeze({
    appEnv,
    intelDriver,
    rdapBaseUrl: env.RDAP_BASE_URL,
    extractMaxTextChars: env.EXTRACT_MAX_TEXT_CHARS,
    tesseractPath: env.TESSERACT_PATH ?? env.TESSERACT_CMD ?? 'tesseract',
    tessdataPrefix: env.TESSDATA_PREFIX,
    ocrLanguages: env.OCR_LANGUAGES ?? 'rus+eng',
    pageTimeoutMs: env.PARSE_PAGE_TIMEOUT_S * 1000,
    publicWebUrl: (env.PUBLIC_WEB_URL ?? env.WEB_PUBLIC_URL ?? env.APP_URL ?? fallbackPublicUrl).replace(/\/+$/, ''),
    intakeWorkspaceConcurrency: env.INTAKE_WORKSPACE_CONCURRENCY,
  });
}
