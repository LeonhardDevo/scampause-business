export type ExcerptSegment = {
    text: string;
    kind: 'plain' | 'value' | 'phrase';
};

const ENTITIES: Record<string, string> = {
    amp: '&',
    lt: '<',
    gt: '>',
    quot: '"',
    apos: "'",
    nbsp: ' ',
};

export function decodeEntities(value: string): string {
    return value.replace(
        /&(#x[0-9a-f]+|#\d+|[a-z]+);/gi,
        (match, entity: string) => {
            if (entity[0] === '#') {
                const code =
                    entity[1] === 'x' || entity[1] === 'X'
                        ? Number.parseInt(entity.slice(2), 16)
                        : Number.parseInt(entity.slice(1), 10);

                return Number.isFinite(code) && code > 0 && code < 0x110000
                    ? String.fromCodePoint(code)
                    : match;
            }

            return ENTITIES[entity.toLowerCase()] ?? match;
        },
    );
}

export function parseExcerpt(html: string): ExcerptSegment[] {
    const segments: ExcerptSegment[] = [];
    const pattern = /<mark>([\s\S]*?)<\/mark>/gi;
    let cursor = 0;

    for (const match of html.matchAll(pattern)) {
        const index = match.index ?? 0;

        if (index > cursor) {
            segments.push({
                text: decodeEntities(
                    html.slice(cursor, index).replace(/<[^>]*>/g, ''),
                ),
                kind: 'plain',
            });
        }

        segments.push({
            text: decodeEntities(match[1].replace(/<[^>]*>/g, '')),
            kind: 'value',
        });
        cursor = index + match[0].length;
    }

    if (cursor < html.length) {
        segments.push({
            text: decodeEntities(html.slice(cursor).replace(/<[^>]*>/g, '')),
            kind: 'plain',
        });
    }

    return segments.filter((segment) => segment.text !== '');
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function phrasePattern(phrases: string[]): RegExp | null {
    const variants = phrases
        .map((phrase) => phrase.trim())
        .filter((phrase) => phrase.length >= 2)
        .sort((a, b) => b.length - a.length)
        .map((phrase) =>
            escapeRegExp(phrase).replace(/'/g, "['’]").replace(/\s+/g, '\\s+'),
        );

    if (variants.length === 0) {
        return null;
    }

    return new RegExp(
        `(?<![\\p{L}\\p{N}])(${variants.join('|')})(?![\\p{L}\\p{N}])`,
        'giu',
    );
}

export function highlightPhrases(
    segments: ExcerptSegment[],
    phrases: string[],
): ExcerptSegment[] {
    const pattern = phrasePattern(phrases);

    if (!pattern) {
        return segments;
    }

    const out: ExcerptSegment[] = [];

    for (const segment of segments) {
        if (segment.kind !== 'plain') {
            out.push(segment);
            continue;
        }

        let cursor = 0;

        for (const match of segment.text.matchAll(pattern)) {
            const index = match.index ?? 0;

            if (index > cursor) {
                out.push({
                    text: segment.text.slice(cursor, index),
                    kind: 'plain',
                });
            }

            out.push({ text: match[0], kind: 'phrase' });
            cursor = index + match[0].length;
        }

        if (cursor < segment.text.length) {
            out.push({ text: segment.text.slice(cursor), kind: 'plain' });
        }
    }

    return out;
}

export function buildExcerpt(
    html: string,
    phrases: string[],
): ExcerptSegment[] {
    return highlightPhrases(parseExcerpt(html), phrases);
}

export function countMatches(segments: ExcerptSegment[]): number {
    return segments.filter((segment) => segment.kind === 'phrase').length;
}
