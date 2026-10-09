<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PasswordConfirmModal from './components/PasswordConfirmModal.vue';
import { user } from './config';
import { useSession } from './composables/session';

const route = useRoute();
const router = useRouter();
const { me, error, connections } = useSession();

/** The connection comes from the URL (/:label/…), so links and reloads keep it. */
const selected = computed({
    get: () => (typeof route.params.label === 'string' ? route.params.label : ''),
    set: (label: string) => {
        if (label !== '') {
            void router.push({ name: 'connection', params: { label } });
        }
    },
});
const current = computed(() => connections.value.find((connection) => connection.label === selected.value));
</script>

<template>
    <div class="flex h-screen flex-col">
        <header class="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-zinc-200 bg-white px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900">
            <router-link to="/" class="rounded text-base font-semibold tracking-tight focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none">DbExplorer</router-link>

            <div class="flex items-center gap-2">
                <label for="dbx-connection" class="text-sm text-zinc-500 dark:text-zinc-400">Connection</label>
                <select
                    id="dbx-connection"
                    v-model="selected"
                    :disabled="connections.length === 0"
                    class="dbx-input"
                >
                    <option v-if="connections.length === 0" value="">None available</option>
                    <option v-else-if="!current" value="" disabled>Choose…</option>
                    <option v-for="connection in connections" :key="connection.label" :value="connection.label">
                        {{ connection.label }}
                    </option>
                </select>
                <span
                    v-if="current"
                    class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                    :title="`Your role on ${current.label}`"
                >{{ current.role }}</span>
            </div>

            <span class="ml-auto text-sm text-zinc-500 dark:text-zinc-400">{{ me?.user.name ?? user.name }}</span>
        </header>

        <div
            v-if="error"
            role="alert"
            class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
        >
            {{ error }}
        </div>
        <div
            v-for="(warning, index) in me?.warnings ?? []"
            :key="index"
            role="status"
            class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
        >
            {{ warning }}
        </div>

        <main class="flex min-h-0 flex-1 flex-col">
            <router-view />
        </main>

        <PasswordConfirmModal />
    </div>
</template>
