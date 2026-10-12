import { user } from '../config';

// localStorage can be missing, full or blocked (private windows, previews):
// every access is wrapped, and callers always have a fallback.
//
// Keys are scoped to the signed-in user, so the next person on a shared
// browser doesn't get their SQL tabs, and everything is cleared when the
// session ends (clearStorage(), on a 401/419).

const PREFIX = 'dbexplorer:';
const USER_PREFIX = `${PREFIX}u:${user.storageKey}:`;

/** Set once the storage was cleared: a write still pending (a debounced tab save) must not put it back. */
let cleared = false;

export function readStorage<T>(key: string, fallback: T): T {
    try {
        const raw = window.localStorage.getItem(USER_PREFIX + key);

        return raw === null ? fallback : (JSON.parse(raw) as T);
    } catch {
        return fallback;
    }
}

export function writeStorage(key: string, value: unknown): void {
    if (cleared) {
        return;
    }

    try {
        window.localStorage.setItem(USER_PREFIX + key, JSON.stringify(value));
    } catch {
        // Storage is a convenience: losing it only loses the saved tabs.
    }
}

/** Remove DbExplorer's keys: every user's (`all`), or all but the signed-in user's. */
function removeKeys(all: boolean): void {
    try {
        const keys: string[] = [];

        for (let index = 0; index < window.localStorage.length; index++) {
            const key = window.localStorage.key(index);

            if (key !== null && key.startsWith(PREFIX) && (all || !key.startsWith(USER_PREFIX))) {
                keys.push(key);
            }
        }

        keys.forEach((key) => window.localStorage.removeItem(key));
    } catch {
        // Blocked storage holds nothing to clear.
    }
}

/** The session ended: forget everything DbExplorer kept in this browser, SQL included. */
export function clearStorage(): void {
    cleared = true;
    removeKeys(true);
}

// Only the signed-in user's keys may stay. A host logout usually leaves the
// page without another API call, so no 401/419 clears the last user's SQL:
// drop every other user's keys here, and the ones from before keys were per
// user (`dbexplorer:editor:<label>`), which are anyone's.
removeKeys(false);
