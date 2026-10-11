import { computed, ref } from 'vue';
import { api, describeError } from '../api';
import type { Connection, Me, Role } from '../types';

const me = ref<Me | null>(null);
const error = ref<string | null>(null);
let loading: Promise<void> | null = null;

/**
 * The signed-in user and the connections they may use, loaded once from
 * `/api/me` and shared by every component.
 */
export function useSession() {
    loading ??= api<Me>('me')
        .then((data) => {
            me.value = data;
        })
        .catch((e: unknown) => {
            error.value = describeError(e, 'Could not load your session. Refresh the page to try again.');
        });

    return {
        me,
        error,
        ready: loading,
        connections: computed<Connection[]>(() => me.value?.connections ?? []),
    };
}

export function findConnection(label: string): Connection | undefined {
    return me.value?.connections.find((connection) => connection.label === label);
}

// batch 4A
const RANK: Record<Role, number> = { viewer: 0, editor: 1, admin: 2 };

/** Whether the user's role on `label` is `min` or higher (Role::atLeast). UI gating only: the API enforces. */
export function hasRole(label: string, min: Role): boolean {
    const role = findConnection(label)?.role;

    return role !== undefined && RANK[role] >= RANK[min];
}
// end batch 4A
