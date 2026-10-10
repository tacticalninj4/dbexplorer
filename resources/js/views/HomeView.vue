<script setup lang="ts">
import { watchEffect } from 'vue';
import { useRouter } from 'vue-router';
import EmptyState from '../components/EmptyState.vue';
import { useSession } from '../composables/session';

// "/": open the first connection the user may use.
const router = useRouter();
const { me, connections, error } = useSession();

watchEffect(() => {
    const first = connections.value[0];
    if (first) {
        void router.replace({ name: 'connection', params: { label: first.label } });
    }
});
</script>

<template>
    <div class="flex flex-1 items-center justify-center p-8">
        <EmptyState v-if="me && connections.length === 0" icon="database" title="No connections">
            You don't have access to any database connection. Ask an administrator to grant you a role.
        </EmptyState>
        <p v-else-if="!error" class="flex items-center gap-2 text-sm text-fg-muted" role="status"><span class="dbx-spinner" aria-hidden="true" />Loading…</p>
    </div>
</template>
