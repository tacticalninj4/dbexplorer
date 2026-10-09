import { api, segment } from '../api';
import type { Column, DbObject, Extension, Schema } from '../types';

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
}

const catalogs = new Map<string, Catalog>();

function catalog(label: string): Catalog {
    let entry = catalogs.get(label);

    if (!entry) {
        entry = { objects: new Map(), columns: new Map() };
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
