import { cpSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const assets = [['src/rag/prompts', 'dist/rag/prompts']];

for (const [from, to] of assets) {
  const source = resolve(root, from);
  if (!existsSync(source)) continue;
  cpSync(source, resolve(root, to), { recursive: true, filter: (path) => !path.endsWith('.ts') });
}
