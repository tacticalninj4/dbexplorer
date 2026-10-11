import { reactive } from 'vue';
import { api, ApiError, segment } from '../api';
import type { Tone } from '../components/BaseBadge.vue';
import type { IconName } from '../components/BaseIcon.vue';
import type { Role } from '../types';

/**
 * Background runs (PRD §7.5): the shapes of the runs API (RunsController and
 * Run::toApi()) and the calls every screen that queues work shares: poll a
 * run, cancel it, download its result through a short-lived signed URL.
 */

export const RUN_STATUSES = ['queued', 'running', 'succeeded', 'failed', 'cancelled'] as const;

export type RunStatus = (typeof RUN_STATUSES)[number];

/** One script statement's outcome: never its SQL text (Run::toApi()). */
export interface RunStatement {
    line: number;
    status: 'succeeded' | 'failed' | 'skipped' | 'rolled_back' | 'cancelled';
    duration_ms: number | null;
    affected: number | null;
}

/** One rejected import row: its data row number and the redacted server message (F7). */
export interface RunRowError {
    row: number;
    error: string;
}

export interface BackgroundRun {
    id: number;
    type: string;
    status: RunStatus;
    progress: number | null;
    label: string;
    role: Role;
    user_id: string | null;
    cancel_requested: boolean;
    error: string | null;
    result_available: boolean;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
    /** Script runs. */
    statements?: RunStatement[];
    /** Import runs. */
    inserted?: number;
    skipped?: number;
    errors?: RunRowError[];
}

export interface RunsPage {
    data: BackgroundRun[];
    meta: { page: number; per_page: number; has_more: boolean };
}

export const RUN_STATUS: Record<RunStatus, { tone: Tone; icon: IconName; text: string }> = {
    queued: { tone: 'neutral', icon: 'clock', text: 'Queued' },
    running: { tone: 'accent', icon: 'refresh', text: 'Running' },
    succeeded: { tone: 'success', icon: 'check', text: 'Succeeded' },
    failed: { tone: 'danger', icon: 'x-circle', text: 'Failed' },
    cancelled: { tone: 'warning', icon: 'ban', text: 'Cancelled' },
};

/** RunQuery, Export, Import, RunScript, Backup and RunScheduledQuery TYPEs. */
export const RUN_TYPES: Record<string, { title: string; icon: IconName }> = {
    query: { title: 'Background query', icon: 'terminal' },
    export: { title: 'Export', icon: 'download' },
    import: { title: 'Import', icon: 'upload' },
    script: { title: 'Script', icon: 'file' },
    backup: { title: 'Backup', icon: 'database' },
    scheduled_query: { title: 'Scheduled query', icon: 'clock' },
};

export function runType(type: string): { title: string; icon: IconName } {
    return RUN_TYPES[type] ?? { title: type, icon: 'activity' };
}

export function isActive(run: Pick<BackgroundRun, 'status'>): boolean {
    return run.status === 'queued' || run.status === 'running';
}

export async function fetchRun(label: string, id: number): Promise<BackgroundRun> {
    return (await api<{ data: BackgroundRun }>(`${segment(label)}/runs/${id}`)).data;
}

/**
 * Ask the server to cancel: 202 while the run is active, 409 (with the run)
 * once it already finished. Either way, the run as it is now.
 */
export async function cancelRun(label: string, id: number): Promise<BackgroundRun> {
    try {
        return (await api<{ data: BackgroundRun }>(`${segment(label)}/runs/${id}/cancel`, { method: 'POST' })).data;
    } catch (e) {
        const body = e instanceof ApiError && e.status === 409 ? e.body : null;
        const run = typeof body === 'object' && body !== null && 'data' in body ? (body as { data: BackgroundRun }).data : null;

        if (run) {
            return run;
        }

        throw e;
    }
}

/**
 * Download a finished run's file: the API hands out a 10-minute signed URL
 * (S16), which the browser follows as an attachment, so the page stays put.
 */
export async function downloadRun(label: string, id: number): Promise<void> {
    const response = await api<{ data: { url: string } }>(`${segment(label)}/runs/${id}/download`);
    window.location.href = response.data.url;
}

export const POLL_MS = 2000;

/**
 * How long a finished run took. The API's timestamps are whole seconds, so
 * anything shorter reads "under 1 s" rather than a false "0 ms".
 */
export function runDuration(run: Pick<BackgroundRun, 'started_at' | 'finished_at'>): string | null {
    if (!run.started_at || !run.finished_at) {
        return null;
    }

    const ms = new Date(run.finished_at).getTime() - new Date(run.started_at).getTime();

    if (Number.isNaN(ms)) {
        return null;
    }

    return ms < 1000 ? 'under 1 s' : ms < 60000 ? `${Math.round(ms / 1000)} s` : `${Math.floor(ms / 60000)} min ${Math.round((ms % 60000) / 1000)} s`;
}

/**
 * Poll one run every 2 s, straight away first, until it finishes or stop()
 * is called. Errors are reported and polling carries on (a blip shouldn't
 * lose track of a running import).
 */
export function watchRun(label: string, id: number, onUpdate: (run: BackgroundRun) => void, onError?: (error: unknown) => void): () => void {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let stopped = false;

    const tick = async (): Promise<void> => {
        try {
            const run = await fetchRun(label, id);

            if (stopped) {
                return;
            }

            onUpdate(run);

            if (!isActive(run)) {
                return;
            }
        } catch (e) {
            if (stopped) {
                return;
            }

            onError?.(e);
        }

        timer = setTimeout(() => void tick(), POLL_MS);
    };

    void tick();

    return () => {
        stopped = true;
        clearTimeout(timer);
    };
}

/**
 * Runs queued from a dialog that closes right away (exports): the app shell
 * shows each as a toast that follows the run and offers its download.
 */
export interface RunToast {
    label: string;
    run: BackgroundRun;
    title: string;
}

export const runToasts = reactive<RunToast[]>([]);

export function notifyRun(label: string, run: BackgroundRun, title: string): void {
    runToasts.push({ label, run, title });

    // A handful at most: the Runs page keeps the full list.
    while (runToasts.length > 4) {
        runToasts.shift();
    }
}

export function dismissRun(id: number): void {
    const index = runToasts.findIndex((toast) => toast.run.id === id);

    if (index !== -1) {
        runToasts.splice(index, 1);
    }
}
