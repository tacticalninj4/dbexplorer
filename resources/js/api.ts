import { basePath, csrfToken } from './config';

export class ApiError extends Error {
    readonly status: number;
    readonly body: unknown;

    constructor(status: number, message: string, body: unknown) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.body = body;
    }
}

/**
 * Cross-cutting API signals. `password-confirm-required` fires on HTTP 423 so
 * the password confirmation modal (M2) can prompt and the caller can retry.
 */
export const apiEvents = new EventTarget();

export interface ApiOptions extends Omit<RequestInit, 'body'> {
    /** Plain values are JSON-encoded; FormData is sent as-is. */
    body?: unknown;
}

export async function api<T>(path: string, options: ApiOptions = {}): Promise<T> {
    const { body, headers, ...init } = options;
    const requestHeaders = new Headers(headers);
    requestHeaders.set('Accept', 'application/json');
    requestHeaders.set('X-Requested-With', 'XMLHttpRequest');
    requestHeaders.set('X-CSRF-TOKEN', csrfToken);

    let requestBody: BodyInit | undefined;
    if (body instanceof FormData) {
        requestBody = body;
    } else if (body !== undefined) {
        requestHeaders.set('Content-Type', 'application/json');
        requestBody = JSON.stringify(body);
    }

    const response = await fetch(`${basePath}/api/${path.replace(/^\/+/, '')}`, {
        ...init,
        headers: requestHeaders,
        body: requestBody,
        credentials: 'same-origin',
    });

    const data = await decode(response);

    if (response.ok) {
        return data as T;
    }

    if (response.status === 401 || response.status === 419) {
        // Session expired or CSRF token stale: a reload re-authenticates.
        window.location.reload();
    } else if (response.status === 423) {
        apiEvents.dispatchEvent(new Event('password-confirm-required'));
    }

    throw new ApiError(response.status, errorMessage(data, response), data);
}

async function decode(response: Response): Promise<unknown> {
    if (response.status === 204) {
        return undefined;
    }

    const text = await response.text();
    if (text === '') {
        return undefined;
    }

    try {
        return JSON.parse(text) as unknown;
    } catch {
        return text;
    }
}

function errorMessage(data: unknown, response: Response): string {
    if (typeof data === 'object' && data !== null && 'message' in data && typeof data.message === 'string' && data.message !== '') {
        return data.message;
    }

    return response.statusText || `Request failed with status ${response.status}`;
}
