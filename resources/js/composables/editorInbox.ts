// Hands SQL from elsewhere (the history page) to the SQL editor, which opens
// it in a new tab when it mounts.

const pending = new Map<string, string>();

export function sendToEditor(label: string, sql: string): void {
    pending.set(label, sql);
}

export function takeForEditor(label: string): string | null {
    const sql = pending.get(label) ?? null;
    pending.delete(label);

    return sql;
}
