import { ref } from 'vue';
import { api, ApiError, describeError, segment } from '../api';
import type { DdlPreview } from '../types';

/**
 * The schema editor API (POST ddl/preview, ddl/execute), shared by the DDL
 * forms. The SPA never builds SQL: it sends a structured operation (see
 * DdlBuilder) and shows what the server returns.
 */

export type DdlOp = Record<string, unknown>;

export async function previewDdl(label: string, op: DdlOp): Promise<DdlPreview> {
    return (await api<{ data: DdlPreview }>(`${segment(label)}/ddl/preview`, { method: 'POST', body: { op } })).data;
}

/** Runs the operation. `run` is set when the server queued it as a background run (202). */
export async function executeDdl(label: string, op: DdlOp): Promise<{ run?: number }> {
    const response = await api<{ data: { run?: { id: number } } }>(`${segment(label)}/ddl/execute`, { method: 'POST', body: { op } });

    return { run: response.data.run?.id };
}

/** A failed preview or execute: the message, plus each invalid field by its dot path (`columns.2.type`). */
export interface DdlFailure {
    message: string;
    fields: Record<string, string>;
}

export function ddlFailure(error: unknown): DdlFailure {
    const fields: Record<string, string> = {};
    const body = error instanceof ApiError && error.status === 422 ? error.body : null;
    const errors = body && typeof body === 'object' ? (body as { errors?: unknown }).errors : null;

    if (errors && typeof errors === 'object') {
        for (const [field, messages] of Object.entries(errors as Record<string, unknown>)) {
            const message: unknown = Array.isArray(messages) ? messages[0] : messages;

            if (typeof message === 'string') {
                fields[field.replace(/^op\./, '')] = message;
            }
        }
    }

    return { message: describeError(error), fields };
}

/** Plain words for a field path, e.g. `columns.2.type` → "Column 3, type". */
export function fieldLabel(path: string): string {
    return path
        .replace(/^columns\.(\d+)\.?/, (_, n: string) => `Column ${Number(n) + 1}${path.length > `columns.${n}`.length ? ', ' : ''}`)
        .replace(/^constraints\.(\d+)\.?/, (_, n: string) => `Constraint ${Number(n) + 1}${path.length > `constraints.${n}`.length ? ', ' : ''}`)
        .replace(/^constraint\./, 'Constraint, ')
        .replace(/references\./, 'references ')
        .replace(/_/g, ' ');
}

export const CONSTRAINT_TYPES = [
    { value: 'primary_key', title: 'Primary key' },
    { value: 'unique', title: 'Unique' },
    { value: 'check', title: 'Check' },
    { value: 'foreign_key', title: 'Foreign key' },
] as const;

export type ConstraintType = (typeof CONSTRAINT_TYPES)[number]['value'];

/** DdlBuilder::FK_ACTIONS; empty leaves the clause out (PostgreSQL's NO ACTION). */
export const FK_ACTIONS = ['no action', 'restrict', 'cascade', 'set null', 'set default'] as const;

/** One constraint as a form edits it. */
export interface ConstraintDraft {
    type: ConstraintType;
    name: string;
    columns: string[];
    expression: string;
    refSchema: string;
    refTable: string;
    refColumns: string[];
    onDelete: string;
    onUpdate: string;
}

export function emptyConstraint(type: ConstraintType = 'unique', refSchema = ''): ConstraintDraft {
    return { type, name: '', columns: [], expression: '', refSchema, refTable: '', refColumns: [], onDelete: '', onUpdate: '' };
}

/** The DdlBuilder Constraint shape for a draft. */
export function constraintOp(draft: ConstraintDraft): DdlOp {
    const name = draft.name.trim() === '' ? {} : { name: draft.name.trim() };

    if (draft.type === 'check') {
        return { type: 'check', ...name, expression: draft.expression };
    }

    if (draft.type === 'foreign_key') {
        return {
            type: 'foreign_key',
            ...name,
            columns: draft.columns,
            references: {
                schema: draft.refSchema,
                table: draft.refTable,
                ...(draft.refColumns.length ? { columns: draft.refColumns } : {}),
                ...(draft.onDelete ? { on_delete: draft.onDelete } : {}),
                ...(draft.onUpdate ? { on_update: draft.onUpdate } : {}),
            },
        };
    }

    return { type: draft.type, ...name, columns: draft.columns };
}

/**
 * Common PostgreSQL types for the type field's suggestions. The server
 * validates whatever is typed (to_regtype), so this is a convenience only.
 */
export const COMMON_TYPES = [
    'bigint', 'integer', 'smallint', 'bigserial', 'serial', 'numeric(12,2)', 'real', 'double precision',
    'text', 'varchar(255)', 'char(1)', 'boolean', 'uuid', 'date', 'time', 'timestamp', 'timestamptz', 'interval',
    'jsonb', 'json', 'bytea', 'inet', 'cidr', 'text[]', 'integer[]',
];

/** A notice for the next object page, e.g. "Created table …" after navigating to it. */
let flash: { text: string; run?: number } | null = null;

export function setFlash(text: string, run?: number): void {
    flash = { text, run };
}

export function takeFlash(): { text: string; run?: number } | null {
    const value = flash;
    flash = null;

    return value;
}

export type CreateKind = 'table' | 'view' | 'materialized_view';

/** An open create form (CreateObjectHost), requested from the tree or the overview. */
export const createRequest = ref<{ kind: CreateKind; schema: string } | null>(null);

export function openCreate(kind: CreateKind, schema: string): void {
    createRequest.value = { kind, schema };
}
