// Response shapes of the DbExplorer API. The PHP controllers are the contract:
// src/Http/Controllers/*Controller.php and src/Drivers/Postgres/*.

export type Role = 'viewer' | 'editor' | 'admin';

export interface Connection {
    label: string;
    role: Role;
}

export interface Me {
    user: { name: string };
    connections: Connection[];
    warnings: string[];
}

export interface Schema {
    name: string;
    owner: string;
    comment: string | null;
}

export interface Extension {
    name: string;
    version: string;
    schema: string;
    comment: string | null;
}

export type ObjectType =
    | 'table'
    | 'partitioned_table'
    | 'view'
    | 'materialized_view'
    | 'sequence'
    | 'foreign_table'
    | 'function'
    | 'procedure'
    | 'enum'
    | 'domain'
    | 'composite_type';

export interface DbObject {
    schema: string;
    name: string;
    type: ObjectType;
    /** `name(argtypes)` for functions and procedures, null otherwise. */
    signature: string | null;
    parent: { schema: string; name: string } | null;
    comment: string | null;
}

export interface Column {
    position: number;
    name: string;
    type: string;
    nullable: boolean;
    default: string | null;
    identity: string | null;
    generated: string | null;
    collation: string | null;
    comment: string | null;
}

export interface Index {
    name: string;
    definition: string;
    method: string;
    unique: boolean;
    primary: boolean;
    valid: boolean;
    constraint: string | null;
}

export interface Constraint {
    name: string;
    type: 'primary_key' | 'unique' | 'foreign_key' | 'check' | 'exclusion' | 'trigger';
    definition: string;
    columns: string[];
    /** Null for non-FK constraints and for FKs to a hidden table. */
    references: { schema: string; table: string; columns: string[] } | null;
}

export interface Trigger {
    name: string;
    definition: string;
    function: string;
    enabled: boolean;
}

export interface Stats {
    total_bytes: number;
    table_bytes: number;
    index_bytes: number;
    estimated_rows: number | null;
    seq_scans: number | null;
    index_scans: number | null;
    live_rows: number | null;
    dead_rows: number | null;
    last_vacuum: string | null;
    last_autovacuum: string | null;
    last_analyze: string | null;
    last_autoanalyze: string | null;
}

export interface FunctionDefinition {
    name: string;
    signature: string;
    type: 'function' | 'procedure';
    arguments: string;
    returns: string | null;
    language: string;
    volatility: string;
    security_definer: boolean;
    definition: string;
    comment: string | null;
}

/** A result column. `type` is the PostgreSQL type name, e.g. `int8`, `_text`, `jsonb`. */
export interface ResultColumn {
    name: string;
    type: string;
    masked: boolean;
}

export type Cell = string | number | boolean | null;

export interface RowsPage {
    columns: ResultColumn[];
    rows: Cell[][];
    pagination: 'keyset' | 'offset';
    next_cursor: string | null;
    next_offset: number | null;
    truncated: boolean;
    count: { value: number | null; exact: boolean };
}

/** Classifier::classify(). */
export const CLASSIFICATIONS = ['read', 'dml', 'ddl', 'destructive', 'unknown'] as const;

export type Classification = (typeof CLASSIFICATIONS)[number];

/** The 409 body of the query API when it wants `confirm: true`: `{error: {message, code, classification}}`. */
export interface ConfirmationRequired {
    message: string;
    code: 'confirmation_required';
    classification: Classification;
}

/** The 409 body of a row write that hit a changed row: `{error: {message}, current}`. */
export interface RowConflictBody {
    message: string;
    /** The row as it is in the database now, or null when it was deleted. */
    current: Record<string, Cell> | null;
}

export interface QueryResponse {
    columns: ResultColumn[];
    rows: Cell[][];
    truncated: boolean;
    row_count: number;
    affected_rows: number | null;
    duration_ms: number;
    classification: Classification;
    notices: string[];
    notices_supported: boolean;
    explain: 'plain' | 'analyze' | null;
    plan: unknown[] | null;
}

export interface HistoryEntry {
    id: number;
    role: Role;
    sql: string;
    classification: Classification;
    duration_ms: number | null;
    row_count: number | null;
    outcome: string;
    error: string | null;
    created_at: string;
}

export interface HistoryPage {
    data: HistoryEntry[];
    meta: { page: number; per_page: number; has_more: boolean };
}

/** The 422 body of a failed query: `{error: {message, sqlstate, position}}`. */
export interface QueryError {
    message: string;
    sqlstate: string | null;
    position: number | null;
}

export interface Filter {
    column: string;
    op: FilterOperator;
    value: string;
}

/** RowsQuery::OPERATORS. */
export const FILTER_OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'like', 'ilike', 'in', 'is_null', 'not_null'] as const;

export type FilterOperator = (typeof FILTER_OPERATORS)[number];

/** One statement built by DdlBuilder from a structured operation. */
export interface DdlStatement {
    sql: string;
    classification: 'ddl' | 'destructive';
    /** False for CREATE/DROP INDEX CONCURRENTLY, which cannot run in a transaction. */
    transactional: boolean;
    /** True when execute queues it as a background run: non-transactional statements and every materialized view refresh. */
    background: boolean;
    /** True when the statement embeds raw SQL expressions the builder does not parse. */
    raw_expressions: boolean;
}

/** POST ddl/preview: the SQL for the operation, nothing runs. */
export interface DdlPreview {
    statements: DdlStatement[];
    /** A best-effort Laravel `Schema::` snippet, or null when Blueprint can't express it. */
    migration: string | null;
}

/** POST ddl/execute: the statements that ran. */
export interface DdlResult {
    statements: DdlStatement[];
    duration_ms: number;
    notices: string[];
    notices_supported: boolean;
}
