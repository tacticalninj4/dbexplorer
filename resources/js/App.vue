<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api, ApiError } from './api';
import { user } from './config';

interface Connection {
    label: string;
    role: string;
}

interface Me {
    user: { name: string };
    connections: Connection[];
    warnings: string[];
}

const me = ref<Me | null>(null);
const error = ref<string | null>(null);
const selected = ref('');

const connections = computed(() => me.value?.connections ?? []);
const current = computed(() => connections.value.find((connection) => connection.label === selected.value));

onMounted(async () => {
    try {
        me.value = await api<Me>('me');
        selected.value = me.value.connections[0]?.label ?? '';
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Could not load your session. Refresh the page to try again.';
    }
});
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <header class="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-base font-semibold tracking-tight">DbExplorer</span>

            <div class="flex items-center gap-2">
                <label for="dbx-connection" class="text-sm text-zinc-500 dark:text-zinc-400">Connection</label>
                <select
                    id="dbx-connection"
                    v-model="selected"
                    :disabled="connections.length === 0"
                    class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm focus:ring-2 focus:ring-zinc-400 focus:outline-none disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-800"
                >
                    <option v-if="connections.length === 0" value="">None available</option>
                    <option v-for="connection in connections" :key="connection.label" :value="connection.label">
                        {{ connection.label }}
                    </option>
                </select>
                <span
                    v-if="current"
                    class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
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

        <main class="flex flex-1 flex-col">
            <router-view />
        </main>
    </div>
</template>
