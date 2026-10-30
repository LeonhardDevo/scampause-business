export const COOKIE_NOTICE_NAME = 'cookie_notice';

export const COOKIE_NOTICE_MAX_AGE = 60 * 60 * 24 * 365;

export function hasCookie(cookieHeader: string, name: string): boolean {
    return cookieHeader
        .split(';')
        .map((part) => part.trim())
        .some((part) => part.split('=')[0] === name);
}

export function noticeCookieValue(secure: boolean): string {
    const parts = [
        `${COOKIE_NOTICE_NAME}=1`,
        'path=/',
        `max-age=${COOKIE_NOTICE_MAX_AGE}`,
        'SameSite=Lax',
    ];

    if (secure) {
        parts.push('Secure');
    }

    return parts.join(';');
}
