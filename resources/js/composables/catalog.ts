import { ref } from 'vue';
import { api, segment } from '../api';
import type { Column, Constraint, DbObject, Extension, Index, Schema } from '../types';

/**
 * Catalog lookups per connection label, cached for the page's lifetime so the
 * tree, the detail view and the editor's autocomplete share one fetch each.
 * A failed request is dropped from the cache, so the next call retries.
 */
interface Catalog {
    schemas?: Promise<Schema[]>;
    extensions?: Promise<Extension[]>;
    objects: Map<string, Promise<DbObject[]>>;
    columns: Map<string, Promise<Column[]>>;
    constraints: Map<string, Promise<Constraint[]>>;
    indexes: Map<string, Promise<Index[]>>;
}

const catalogs = new Map<string, Catalog>();

/** Bumped by invalidate(), so views that hold catalog data (the tree) can reload. */
export const catalogVersion = ref(0);

function catalog(label: string): Catalog {
    let entry = catalogs.get(label);

    if (!entry) {
        entry = { objects: new Map(), columns: new Map(), constraints: new Map(), indexes: new Map() };
        catalogs.set(label, entry);
    }

    return entry;
}

function cached<T>(map: Map<string, Promise<T>>, key: string, load: () => Promise<T>): Promise<T> {
    let promise = map.get(key);

    if (!promise) {
        promise = load();
        promise.catch(() => map.delete(key));
        map.set(key, promise);
    }

    return promise;
}

async function data<T>(path: string): Promise<T> {
    return (await api<{ data: T }>(path)).data;
}

/** Drop the cached objects, columns, constraints and indexes of `label` (all, or one schema's objects). */
export function invalidate(label: string, schema?: string): void {
    const entry = catalogs.get(label);

    if (!entry) {
        return;
    }

    catalogVersion.value++;

    if (schema === undefined) {
        entry.objects.clear();
        entry.columns.clear();
        entry.constraints.clear();
        entry.indexes.clear();
        entry.schemas = undefined;
        return;
    }

    for (const key of [...entry.objects.keys()]) {
        if (key === schema || key.startsWith(`${JSON.stringify([schema])}`)) {
            entry.objects.delete(key);
        }
    }
}

export function schemas(label: string): Promise<Schema[]> {
    const entry = catalog(label);

    if (!entry.schemas) {
        entry.schemas = data<Schema[]>(`${segment(label)}/schemas`);
        entry.schemas.catch(() => (entry.schemas = undefined));
    }

    return entry.schemas;
}

export function extensions(label: string): Promise<Extension[]> {
    const entry = catalog(label);

    if (!entry.extensions) {
        entry.extensions = data<Extension[]>(`${segment(label)}/extensions`);
        entry.extensions.catch(() => (entry.extensions = undefined));
    }

    return entry.extensions;
}

export function objects(label: string, schema: string): Promise<DbObject[]> {
    return cached(catalog(label).objects, schema, () => data<DbObject[]>(`${segment(label)}/schemas/${segment(schema)}/objects`));
}

export function columns(label: string, schema: string, name: string): Promise<Column[]> {
    return cached(catalog(label).columns, JSON.stringify([schema, name]), () =>
        data<Column[]>(`${segment(label)}/objects/${segment(schema)}/${segment(name)}/columns`),
    );
}

export function constraints(label: string, schema: string, name: string): Promise<Constraint[]> {
    return cached(catalog(label).constraints, JSON.stringify([schema, name]), () =>
        data<Constraint[]>(`${segment(label)}/objects/${segment(schema)}/${segment(name)}/constraints`),
    );
}

export function indexes(label: string, schema: string, name: string): Promise<Index[]> {
    return cached(catalog(label).indexes, JSON.stringify([schema, name]), () =>
        data<Index[]>(`${segment(label)}/objects/${segment(schema)}/${segment(name)}/indexes`),
    );
}

/** The table's primary key columns, or null when it has none. Views and matviews have none. */
export async function primaryKey(label: string, schema: string, name: string): Promise<string[] | null> {
    const found = (await constraints(label, schema, name)).find((constraint) => constraint.type === 'primary_key');

    return found ? found.columns : null;
}
