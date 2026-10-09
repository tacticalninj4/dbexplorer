import type { CompletionContext, CompletionResult, CompletionSource } from '@codemirror/autocomplete';
import { PostgreSQL, schemaCompletionSource } from '@codemirror/lang-sql';
import type { SQLNamespace } from '@codemirror/lang-sql';
import * as catalog from '../composables/catalog';
import type { DbObject } from '../types';

/**
 * Schema, table and column completion fed from the API.
 *
 * Schemas and their objects load on the first completion; columns load for the
 * relations the SQL names (after FROM, JOIN, UPDATE, INTO) or that the cursor
 * is qualifying. Everything is cached per connection (composables/catalog), and
 * lang-sql's own schema completion does the parsing, aliases and quoting.
 */

const RELATIONS = ['table', 'partitioned_table', 'view', 'materialized_view', 'foreign_table'];
const MAX_COLUMN_LOADS = 12;
const IDENT = String.raw`(?:"(?:[^"]|"")+"|[A-Za-z_][\w$]*)`;
const REFERENCE = new RegExp(String.raw`\b(?:from|join|update|into|table)\s+(${IDENT}(?:\s*\.\s*${IDENT})?)`, 'gi');

function unquote(part: string): string {
    return part.startsWith('"') ? part.slice(1, -1).replace(/""/g, '"') : part.toLowerCase();
}

function splitName(text: string): string[] {
    return (text.match(new RegExp(IDENT, 'g')) ?? []).map(unquote);
}

export function catalogCompletion(label: string, defaultSchema = 'public'): CompletionSource {
    const relations = new Map<string, DbObject[]>();
    const columns = new Map<string, string[]>();
    let loaded: Promise<void> | null = null;
    let source: CompletionSource | null = null;

    function loadRelations(): Promise<void> {
        loaded ??= catalog.schemas(label).then(async (schemas) => {
            await Promise.all(
                schemas.map(async (schema) => {
                    const objects = await catalog.objects(label, schema.name).catch(() => []);
                    relations.set(schema.name, objects.filter((object) => RELATIONS.includes(object.type)));
                }),
            );
        });
        loaded.catch(() => (loaded = null));

        return loaded;
    }

    function resolve(parts: string[]): DbObject | undefined {
        if (parts.length >= 2) {
            return relations.get(parts[0] as string)?.find((object) => object.name === parts[1]);
        }

        const name = parts[0];
        const preferred = relations.get(defaultSchema)?.find((object) => object.name === name);
        if (preferred) {
            return preferred;
        }

        const matches = [...relations.values()].flat().filter((object) => object.name === name);

        return matches.length === 1 ? matches[0] : undefined;
    }

    async function loadColumns(context: CompletionContext): Promise<boolean> {
        const text = context.state.doc.sliceString(0, Math.min(context.state.doc.length, 50000));
        const wanted = new Map<string, DbObject>();

        for (const match of text.matchAll(REFERENCE)) {
            const object = resolve(splitName(match[1] ?? ''));
            if (object) {
                wanted.set(`${object.schema}.${object.name}`, object);
            }
        }

        // `schema.table.` or `table.` right before the cursor.
        const qualifier = context.matchBefore(new RegExp(String.raw`${IDENT}(?:\.${IDENT})?\.[\w$"]*$`));
        if (qualifier) {
            const parts = splitName(qualifier.text).slice(0, qualifier.text.endsWith('.') ? undefined : -1);
            const object = resolve(parts.slice(-2));
            if (object) {
                wanted.set(`${object.schema}.${object.name}`, object);
            }
        }

        const missing = [...wanted.entries()].filter(([key]) => !columns.has(key)).slice(0, MAX_COLUMN_LOADS);

        await Promise.all(
            missing.map(async ([key, object]) => {
                const list = await catalog.columns(label, object.schema, object.name).catch(() => null);
                if (list) {
                    columns.set(key, list.map((column) => column.name));
                }
            }),
        );

        return missing.length > 0;
    }

    function build(): CompletionSource {
        const namespace: Record<string, Record<string, string[]>> = {};

        for (const [schema, objects] of relations) {
            const tables: Record<string, string[]> = {};
            for (const object of objects) {
                tables[object.name] = columns.get(`${schema}.${object.name}`) ?? [];
            }
            namespace[schema] = tables;
        }

        return schemaCompletionSource({ dialect: PostgreSQL, schema: namespace as SQLNamespace, defaultSchema });
    }

    return async (context: CompletionContext): Promise<CompletionResult | null> => {
        if (!context.explicit && !context.matchBefore(/[\w$."]+$/)) {
            return null;
        }

        try {
            await loadRelations();
            const changed = await loadColumns(context);
            if (changed || source === null) {
                source = build();
            }
        } catch {
            return null;
        }

        return source(context);
    };
}
