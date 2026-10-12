import { clearStorage } from './composables/storage';
import { basePath, csrfToken } from './config';
import { CLASSIFICATIONS } from './types';
import type { ConfirmationRequired, QueryError } from './types';

export class ApiError extends Error {
    readonly status: number;
    readonly body: unknown;

    constructor(status: number, message: string, body: unknown) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.body = body;
    }

    /** The `{error: {message, sqlstate, position}}` body of a failed query, if this is one. */
    get queryError(): QueryError | null {
        const error = isObject(this.body) ? this.body.error : null;

        if (!isObject(error) || typeof error.message !== 'string') {
            return null;
        }

        return {
            message: error.message,
            sqlstate: typeof error.sqlstate === 'string' ? error.sqlstate : null,
            position: typeof error.position === 'number' ? error.position : null,
        };
    }

    /**
     * The 409 body of the query API when it wants the statement confirmed
     * (`code: 'confirmation_required'`), if this is one: the classification to
     * show, so the SPA never guesses it itself (PRD S11).
     */
    get confirmationRequired(): ConfirmationRequired | null {
        const error = isObject(this.body) ? this.body.error : null;

        if (!isObject(error) || error.code !== 'confirmation_required' || typeof error.message !== 'string') {
            return null;
        }

        const classification = CLASSIFICATIONS.find((value) => value === error.classification);

        return {
            message: error.message,
            code: 'confirmation_required',
            classification: classification ?? 'unknown',
        };
    }
}

/**
 * Cross-cutting API signals.
 *
 * `password-confirm-required` fires on HTTP 423 as a {@link PasswordConfirmEvent}.
 * A listener (the password modal) calls `preventDefault()` to take it, then
 * `detail.settle(true)` once the password is confirmed, or `settle(false)`
 * when the user gives up. api() then retries the request once, or throws the 423.
 */
export const apiEvents = new EventTarget();

export type PasswordConfirmEvent = CustomEvent<{ settle: (confirmed: boolean) => void }>;

export interface ApiOptions extends Omit<RequestInit, 'body'> {
    /** Plain values are JSON-encoded; FormData is sent as-is. */
    body?: unknown;
    /** Query string parameters; arrays and objects use PHP's `a[0][b]=` notation. */
    query?: Record<string, unknown>;
}

/** One pending confirmation, shared by every request that got a 423 meanwhile. */
let confirmation: Promise<boolean> | null = null;

function requestPasswordConfirmation(): Promise<boolean> {
    confirmation ??= new Promise<boolean>((resolve) => {
        const event: PasswordConfirmEvent = new CustomEvent('password-confirm-required', {
            cancelable: true,
            detail: { settle: resolve },
        });

        // Nobody took it: there is nothing to wait for.
        if (apiEvents.dispatchEvent(event)) {
            resolve(false);
        }
    }).finally(() => {
        confirmation = null;
    });

    return confirmation;
}

export async function api<T>(path: string, options: ApiOptions = {}): Promise<T> {
    return request<T>(path, options, true);
}

async function request<T>(path: string, options: ApiOptions, confirmable: boolean): Promise<T> {
    const { body, headers, query, ...init } = options;
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

    const search = query ? queryString(query) : '';
    const response = await fetch(`${basePath}/api/${path.replace(/^\/+/, '')}${search === '' ? '' : `?${search}`}`, {
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
        // Whoever signs in next may be someone else: the saved SQL goes first.
        clearStorage();
        window.location.reload();
    } else if (response.status === 423 && confirmable && (await requestPasswordConfirmation())) {
        // Confirmed: retry once. A second 423 is thrown to the caller.
        return request<T>(path, options, false);
    }

    throw new ApiError(response.status, errorMessage(data, response), data);
}

/** A URL path segment for a schema, object or function name. */
export function segment(name: string): string {
    return encodeURIComponent(name);
}

export function queryString(params: Record<string, unknown>): string {
    const search = new URLSearchParams();

    const add = (key: string, value: unknown): void => {
        if (value === undefined || value === null) {
            return;
        }

        if (Array.isArray(value)) {
            value.forEach((item, index) => add(`${key}[${index}]`, item));
        } else if (isObject(value)) {
            Object.entries(value).forEach(([name, item]) => add(`${key}[${name}]`, item));
        } else {
            search.append(key, String(value));
        }
    };

    Object.entries(params).forEach(([key, value]) => add(key, value));

    return search.toString();
}

/** A message for anything an API call can throw, for display. */
export function describeError(error: unknown, fallback = 'Something went wrong. Try again.'): string {
    if (error instanceof ApiError) {
        return error.message;
    }

    return error instanceof TypeError ? 'The server could not be reached. Check your connection and try again.' : fallback;
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
    if (isObject(data)) {
        // Validation (422): the field message says more than "The given data was invalid."
        if (isObject(data.errors)) {
            const first = Object.values(data.errors)[0];
            const message: unknown = Array.isArray(first) ? first[0] : first;

            if (typeof message === 'string' && message !== '') {
                return message;
            }
        }

        // A failed query (422): {error: {message, sqlstate, position}}.
        if (isObject(data.error) && typeof data.error.message === 'string' && data.error.message !== '') {
            return data.error.message;
        }

        if (typeof data.message === 'string' && data.message !== '') {
            return data.message;
        }
    }

    return response.statusText || `Request failed with status ${response.status}`;
}

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
