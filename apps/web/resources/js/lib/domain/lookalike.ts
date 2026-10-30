export type DiffSegment = { text: string; changed: boolean };

export type LookalikeKind =
    | 'added'
    | 'removed'
    | 'replaced'
    | 'swapped'
    | 'homoglyph'
    | 'affix'
    | 'tld_swap'
    | 'same';

export type LookalikeDescription = {
    kind: LookalikeKind;
    count: number;
    segments: DiffSegment[];
};

type Op = { type: 'keep' | 'insert' | 'delete'; char: string };

function diffOps(original: string, candidate: string): Op[] {
    const rows = original.length;
    const cols = candidate.length;
    const table: number[][] = Array.from({ length: rows + 1 }, () =>
        Array.from({ length: cols + 1 }, () => 0),
    );

    for (let i = rows - 1; i >= 0; i--) {
        for (let j = cols - 1; j >= 0; j--) {
            table[i][j] =
                original[i] === candidate[j]
                    ? table[i + 1][j + 1] + 1
                    : Math.max(table[i + 1][j], table[i][j + 1]);
        }
    }

    const ops: Op[] = [];
    let i = 0;
    let j = 0;

    while (i < rows && j < cols) {
        if (original[i] === candidate[j]) {
            ops.push({ type: 'keep', char: candidate[j] });
            i++;
            j++;
        } else if (table[i + 1][j] >= table[i][j + 1]) {
            ops.push({ type: 'delete', char: original[i] });
            i++;
        } else {
            ops.push({ type: 'insert', char: candidate[j] });
            j++;
        }
    }

    while (i < rows) {
        ops.push({ type: 'delete', char: original[i++] });
    }

    while (j < cols) {
        ops.push({ type: 'insert', char: candidate[j++] });
    }

    return ops;
}

export function diffSegments(
    original: string,
    candidate: string,
): DiffSegment[] {
    const segments: DiffSegment[] = [];

    for (const op of diffOps(original, candidate)) {
        if (op.type === 'delete') {
            continue;
        }

        const changed = op.type === 'insert';
        const last = segments[segments.length - 1];

        if (last && last.changed === changed) {
            last.text += op.char;
        } else {
            segments.push({ text: op.char, changed });
        }
    }

    return segments;
}

function isTransposition(original: string, candidate: string): boolean {
    if (original.length !== candidate.length) {
        return false;
    }

    const positions: number[] = [];

    for (let index = 0; index < original.length; index++) {
        if (original[index] !== candidate[index]) {
            positions.push(index);
        }
    }

    return (
        positions.length === 2 &&
        positions[1] === positions[0] + 1 &&
        original[positions[0]] === candidate[positions[1]] &&
        original[positions[1]] === candidate[positions[0]]
    );
}

export function describeLookalike(
    domain: string,
    original: string,
    technique: string | null = null,
): LookalikeDescription {
    const candidate = domain.toLowerCase();
    const reference = original.toLowerCase();
    const segments = diffSegments(reference, candidate);

    if (candidate === reference) {
        return { kind: 'same', count: 0, segments };
    }

    const ops = diffOps(reference, candidate);
    const inserted = ops.filter((op) => op.type === 'insert').length;
    const deleted = ops.filter((op) => op.type === 'delete').length;

    if (technique === 'homoglyph') {
        return {
            kind: 'homoglyph',
            count: Math.max(inserted, deleted),
            segments,
        };
    }

    if (technique === 'tld_swap') {
        return { kind: 'tld_swap', count: 1, segments };
    }

    if (technique === 'affix') {
        return { kind: 'affix', count: inserted, segments };
    }

    if (isTransposition(reference, candidate)) {
        return { kind: 'swapped', count: 1, segments };
    }

    if (deleted === 0) {
        return { kind: 'added', count: inserted, segments };
    }

    if (inserted === 0) {
        return { kind: 'removed', count: deleted, segments };
    }

    return { kind: 'replaced', count: Math.max(inserted, deleted), segments };
}
