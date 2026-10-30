import type { PostFusionContext, PostFusionHook, RetrievedChunk } from '../retrieval/hybrid.js';

function tagValue(tags: readonly string[], prefix: string): string | null {
  const tag = tags.find((item) => item.startsWith(prefix));
  return tag ? tag.slice(prefix.length) : null;
}

export function dedupeByLocale(hits: RetrievedChunk[], ctx: Pick<PostFusionContext, 'locale'>): RetrievedChunk[] {
  if (!ctx.locale) return hits;
  const langsByFamily = new Map<string, Set<string>>();
  for (const hit of hits) {
    const family = tagValue(hit.tags, 'family:');
    const lang = tagValue(hit.tags, 'lang:');
    if (!family || !lang) continue;
    const langs = langsByFamily.get(family) ?? new Set<string>();
    langs.add(lang);
    langsByFamily.set(family, langs);
  }
  return hits.filter((hit) => {
    const family = tagValue(hit.tags, 'family:');
    const lang = tagValue(hit.tags, 'lang:');
    if (!family || !lang) return true;
    const langs = langsByFamily.get(family);
    if (!langs || langs.size < 2) return true;
    return langs.has(ctx.locale ?? '') ? lang === ctx.locale : true;
  });
}

export const localeDedupe: PostFusionHook = (hits, ctx) => dedupeByLocale(hits, ctx);
