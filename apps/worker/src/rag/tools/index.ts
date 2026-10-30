import { SearchDocumentsTool } from './searchDocuments.js';
import { ToolRegistry, type Tool } from './types.js';

export type ToolRegistrar = (registry: ToolRegistry) => void;

export function buildRegistry(extra: readonly ToolRegistrar[] = []): ToolRegistry {
  const registry = new ToolRegistry();
  registry.register(new SearchDocumentsTool());
  for (const register of extra) register(registry);
  return registry;
}

export { SearchDocumentsTool, ToolRegistry, type Tool };
export { ChatUser, ToolError, toolDefinition, type ChatAction, type ToolContext, type ToolResult } from './types.js';
