// Quote PostgreSQL identifiers for SQL the UI writes into the editor.

const RESERVED = new Set([
    'all', 'analyse', 'analyze', 'and', 'any', 'array', 'as', 'asc', 'between', 'both', 'case', 'cast', 'check', 'collate', 'column',
    'constraint', 'create', 'current_date', 'current_role', 'current_time', 'current_timestamp', 'current_user', 'default',
    'deferrable', 'desc', 'distinct', 'do', 'else', 'end', 'except', 'false', 'fetch', 'for', 'foreign', 'from', 'grant', 'group',
    'having', 'in', 'initially', 'intersect', 'into', 'is', 'join', 'lateral', 'leading', 'like', 'limit', 'localtime', 'localtimestamp',
    'not', 'null', 'offset', 'on', 'only', 'or', 'order', 'placing', 'primary', 'references', 'returning', 'select', 'session_user',
    'some', 'symmetric', 'table', 'then', 'to', 'trailing', 'true', 'union', 'unique', 'user', 'using', 'variadic', 'when', 'where',
    'window', 'with',
]);

export function quoteIdent(name: string): string {
    return /^[a-z_][a-z0-9_$]*$/.test(name) && !RESERVED.has(name) ? name : `"${name.replace(/"/g, '""')}"`;
}

export function qualified(schema: string, name: string): string {
    return `${quoteIdent(schema)}.${quoteIdent(name)}`;
}
