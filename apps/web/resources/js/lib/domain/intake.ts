export type IntakeFileLike = { name: string; size: number };

export type IntakeIssueCode =
    | 'too_many'
    | 'too_large'
    | 'total_too_large'
    | 'unsupported'
    | 'empty'
    | 'duplicate';

export type IntakeIssue = { code: IntakeIssueCode; name: string };

export const MAX_TOTAL_MB = 40;

export function extensionOf(name: string): string {
    const dot = name.lastIndexOf('.');

    return dot > -1 ? name.slice(dot).toLowerCase() : '';
}

export function acceptAttribute(accept: string[]): string {
    return accept
        .map((item) => (item.startsWith('.') ? item : `.${item}`))
        .join(',');
}

export function fileKind(
    file: IntakeFileLike,
): 'pdf' | 'image' | 'eml' | 'other' {
    const extension = extensionOf(file.name);

    if (extension === '.pdf') {
        return 'pdf';
    }

    if (extension === '.eml') {
        return 'eml';
    }

    if (['.png', '.jpg', '.jpeg', '.webp'].includes(extension)) {
        return 'image';
    }

    return 'other';
}

export function validateIntakeFiles<T extends IntakeFileLike>(
    files: T[],
    accept: string[],
    maxFiles: number,
    maxSizeMb: number,
    maxTotalMb: number = MAX_TOTAL_MB,
): { accepted: T[]; issues: IntakeIssue[] } {
    const allowed = new Set(
        accept.map((item) =>
            (item.startsWith('.') ? item : `.${item}`).toLowerCase(),
        ),
    );
    const accepted: T[] = [];
    const issues: IntakeIssue[] = [];
    const seen = new Set<string>();
    let total = 0;

    for (const file of files) {
        const key = `${file.name}:${file.size}`;

        if (seen.has(key)) {
            issues.push({ code: 'duplicate', name: file.name });
            continue;
        }

        if (!allowed.has(extensionOf(file.name))) {
            issues.push({ code: 'unsupported', name: file.name });
            continue;
        }

        if (file.size <= 0) {
            issues.push({ code: 'empty', name: file.name });
            continue;
        }

        if (file.size > maxSizeMb * 1024 * 1024) {
            issues.push({ code: 'too_large', name: file.name });
            continue;
        }

        if (accepted.length >= maxFiles) {
            issues.push({ code: 'too_many', name: file.name });
            continue;
        }

        if (total + file.size > maxTotalMb * 1024 * 1024) {
            issues.push({ code: 'total_too_large', name: file.name });
            continue;
        }

        seen.add(key);
        total += file.size;
        accepted.push(file);
    }

    return { accepted, issues };
}
