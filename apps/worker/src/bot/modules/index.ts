import type { BotModule } from '../types.js';
import { assistantModule } from './assistant.js';
import { coreModule } from './core.js';
import { privacyModule } from './privacy.js';
import { scampauseModule } from './scampause/index.js';

export const domainModules: BotModule[] = [scampauseModule];

export function defaultModules(): BotModule[] {
  return [coreModule, privacyModule, ...domainModules, assistantModule];
}

export { assistantModule, coreModule, privacyModule, scampauseModule };
