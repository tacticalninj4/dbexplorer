/// <reference types="vite/client" />

export interface DbExplorerUser {
    name: string;
    /** Opaque per-user key that scopes what the SPA keeps in localStorage. */
    storageKey: string;
}

/**
 * Injected by resources/views/app.blade.php before the bundle loads.
 * It never contains connection config (S7).
 */
export interface DbExplorerConfig {
    /** URL path the package is mounted at, e.g. "/dbexplorer". */
    basePath: string;
    csrfToken: string;
    user: DbExplorerUser;
}

declare global {
    interface Window {
        DbExplorer: DbExplorerConfig;
    }
}
