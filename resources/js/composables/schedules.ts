// Scheduled queries (PRD §7.9, docs/security.md §6). The contract is
// ScheduleController::toApi() and its validation rules.

export type NotifyEvent = 'success' | 'failure' | 'threshold';

export interface NotifySettings {
    on: NotifyEvent[];
    /** Mail when a successful run returns at least this many rows. */
    threshold_rows: number | null;
    mail: string[];
}

export interface Schedule {
    id: number;
    saved_query_id: number;
    /** The saved query's name, or null when it's gone. */
    name: string | null;
    /** The current user created the schedule. */
    owner: boolean;
    /** The approved snapshot of the SQL: what actually runs. */
    sql: string | null;
    /** Holds an approved, untampered snapshot: the only state that may run. */
    approved: boolean;
    approved_by: string | null;
    approved_at: string | null;
    allow_writes: boolean;
    allow_ddl: boolean;
    /** False once the saved query's SQL differs from the snapshot. */
    in_sync: boolean;
    cron: string;
    /** Null runs on the app's timezone. */
    timezone: string | null;
    enabled: boolean;
    notify: NotifySettings;
    last_run_at: string | null;
    last_run_id: number | null;
    created_at: string;
}

/** The timezone the server evaluates the cron in. The API doesn't report the app's, so null assumes UTC (Laravel's default). */
export function cronTimeZone(schedule: Pick<Schedule, 'timezone'>): string {
    return schedule.timezone ?? 'UTC';
}

/** The API's validation messages name fields (allow_writes); the form calls them by their labels. */
export function plainPermissions(message: string): string {
    return message.replace(/\ballow_writes\b/g, '“Allow writes”').replace(/\ballow_ddl\b/g, '“Allow schema changes”');
}
