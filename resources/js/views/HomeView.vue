<script setup lang="ts">
import { watchEffect } from 'vue';
import { useRouter } from 'vue-router';
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
        <div v-if="me && connections.length === 0" class="max-w-sm text-center">
            <h1 class="text-lg font-medium">No connections</h1>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                You don't have access to any database connection. Ask an administrator to grant you a role.
            </p>
        </div>
        <p v-else-if="!error" class="text-sm text-zinc-500">Loading…</p>
    </div>
</template>
