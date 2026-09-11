/**
 * Thin wrapper over fetch for a same-origin Sanctum SPA.
 *
 * Authentication rides on the session cookie, so there is no token to keep;
 * what the client does have to do is carry the XSRF header Laravel expects on
 * every mutating request, and turn Laravel's error shapes into one error type
 * the UI can render.
 */

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly errors: Record<string, string[]> = {},
        public readonly code: string | null = null,
    ) {
        super(message)
        this.name = 'ApiError'
    }

    /** First validation message for a field, if the server sent one. */
    firstError(field: string): string | null {
        return this.errors[field]?.[0] ?? null
    }
}

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(^|; )' + name + '=([^;]*)'))

    return match ? decodeURIComponent(match[2]) : null
}

/**
 * Laravel issues the CSRF cookie on this endpoint; it must be fetched once
 * before the first mutating request of a session.
 */
async function ensureCsrfCookie(): Promise<void> {
    if (readCookie('XSRF-TOKEN')) {
        return
    }

    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
}

async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
    if (method !== 'GET') {
        await ensureCsrfCookie()
    }

    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    }

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json'
    }

    const token = readCookie('XSRF-TOKEN')

    if (token) {
        headers['X-XSRF-TOKEN'] = token
    }

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    })

    if (response.status === 204) {
        return undefined as T
    }

    const payload = await response.json().catch(() => ({}))

    if (!response.ok) {
        throw new ApiError(
            payload.message ?? 'Не удалось выполнить запрос.',
            response.status,
            payload.errors ?? {},
            payload.error_code ?? null,
        )
    }

    return payload as T
}

export const api = {
    get: <T>(url: string) => request<T>('GET', url),
    post: <T>(url: string, body?: unknown) => request<T>('POST', url, body ?? {}),
    delete: <T>(url: string) => request<T>('DELETE', url),
}
