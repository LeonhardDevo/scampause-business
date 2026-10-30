export const OPEN_URL_PREFIXES: readonly string[] = [
    '/payment-requests',
    '/approvals',
    '/vendors',
    '/blocked-accounts',
    '/policies',
    '/reports',
    '/knowledge',
    '/dashboard',
] as const;

const SAFE_PATH = /^\/[A-Za-z0-9/_-]*(\?[A-Za-z0-9=&_-]*)?$/;

export function safeAppUrl(value: unknown): string | null {
    if (
        typeof value !== 'string' ||
        value === '' ||
        value.length > 200 ||
        !value.startsWith('/') ||
        value.startsWith('//') ||
        !SAFE_PATH.test(value)
    ) {
        return null;
    }

    return OPEN_URL_PREFIXES.some(
        (prefix) =>
            value === prefix ||
            value.startsWith(`${prefix}/`) ||
            value.startsWith(`${prefix}?`),
    )
        ? value
        : null;
}

export function isRelativeAppPath(value: string): boolean {
    return (
        value.startsWith('/') &&
        !value.startsWith('//') &&
        !value.includes('\\')
    );
}
