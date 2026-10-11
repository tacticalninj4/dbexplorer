import { api, segment } from '../api';

// Saved queries (PRD §7.8). The contract is SavedQueryController::toApi()
// and its validation rules.

export const PARAM_TYPES = ['string', 'int', 'date', 'enum'] as const;

export type ParamType = (typeof PARAM_TYPES)[number];

/** A typed `:name` parameter. A missing `required` means required. */
export interface SavedQueryParam {
    name: string;
    type?: ParamType;
    options?: string[];
    required?: boolean;
}

export interface SavedQuery {
    id: number;
    name: string;
    description: string | null;
    sql: string;
    params: SavedQueryParam[];
    visibility: 'private' | 'shared';
    tags: string[];
    /** The current user owns it: only owners edit or delete. */
    owned: boolean;
    created_at: string;
    updated_at: string;
}

export type SavedQueryInput = Pick<SavedQuery, 'name' | 'description' | 'sql' | 'params' | 'visibility' | 'tags'>;

export async function listSavedQueries(label: string): Promise<SavedQuery[]> {
    return (await api<{ data: SavedQuery[] }>(`${segment(label)}/queries`)).data;
}

export async function saveSavedQuery(label: string, input: SavedQueryInput, id?: number): Promise<SavedQuery> {
    const path = id === undefined ? `${segment(label)}/queries` : `${segment(label)}/queries/${id}`;

    return (await api<{ data: SavedQuery }>(path, { method: id === undefined ? 'POST' : 'PATCH', body: input })).data;
}

export async function deleteSavedQuery(label: string, id: number): Promise<void> {
    await api(`${segment(label)}/queries/${id}`, { method: 'DELETE' });
}

/** Whether a parameter must have a value: the server's `required ?? true`. */
export function isRequired(param: SavedQueryParam): boolean {
    return param.required ?? true;
}
