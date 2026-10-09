// localStorage can be missing, full or blocked (private windows, previews):
// every access is wrapped, and callers always have a fallback.

const PREFIX = 'dbexplorer:';

export function readStorage<T>(key: string, fallback: T): T {
    try {
        const raw = window.localStorage.getItem(PREFIX + key);

        return raw === null ? fallback : (JSON.parse(raw) as T);
    } catch {
        return fallback;
    }
}

export function writeStorage(key: string, value: unknown): void {
    try {
        window.localStorage.setItem(PREFIX + key, JSON.stringify(value));
    } catch {
        // Storage is a convenience: losing it only loses the saved tabs.
    }
}
