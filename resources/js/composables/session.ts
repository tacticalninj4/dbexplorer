import { computed, ref } from 'vue';
import { api, describeError } from '../api';
import type { Connection, Me } from '../types';

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
