<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, describeError, segment } from '../api';
import { sendToEditor } from '../composables/editorInbox';
import { formatDate, formatDuration, formatNumber } from '../composables/format';
import type { HistoryPage } from '../types';

// /:label/history: your own queries on this connection, newest first.
const route = useRoute();
const router = useRouter();
const label = computed(() => String(route.params.label));
const page = computed(() => Math.max(1, Number(route.query.page) || 1));

const history = ref<HistoryPage | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);

watch(
    [label, page],
    async ([currentLabel, currentPage]) => {
        loading.value = true;
        error.value = null;
        try {
            history.value = await api<HistoryPage>(`${segment(currentLabel)}/history`, { query: { page: currentPage } });
        } catch (e) {
            error.value = describeError(e);
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

function open(sql: string): void {
    sendToEditor(label.value, sql);
    void router.push({ name: 'sql', params: { label: label.value } });
}

function goTo(next: number): void {
    void router.push({ query: { ...route.query, page: next > 1 ? String(next) : undefined } });
}

const outcomeClass = (outcome: string) =>
    outcome === 'success' ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-300';
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="flex items-center gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
            <h1 class="text-lg font-semibold">Query history</h1>
            <span class="text-sm text-zinc-500">Your queries on {{ label }}. Click one to open it in the editor.</span>
        </header>

        <div class="min-h-0 flex-1 overflow-auto p-4">
            <p v-if="error" role="alert" class="text-sm text-red-700 dark:text-red-300">{{ error }}</p>
            <p v-else-if="loading && !history" class="text-sm text-zinc-500">Loading…</p>
            <p v-else-if="history && history.data.length === 0" class="text-sm text-zinc-500">No queries yet.</p>
            <ul v-else-if="history" class="space-y-2" :aria-busy="loading">
                <li v-for="entry in history.data" :key="entry.id">
                    <button
                        type="button"
                        class="block w-full rounded-md border border-zinc-200 bg-white p-3 text-left hover:border-sky-400 focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-sky-700"
                        @click="open(entry.sql)"
                    >
                        <span class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ formatDate(entry.created_at) }}</span>
                            <span class="dbx-badge">{{ entry.classification }}</span>
                            <span :class="outcomeClass(entry.outcome)">{{ entry.outcome }}</span>
                            <span>{{ formatDuration(entry.duration_ms) }}</span>
                            <span v-if="entry.row_count !== null">{{ formatNumber(entry.row_count) }} rows</span>
                            <span>as {{ entry.role }}</span>
                        </span>
                        <span class="mt-1.5 line-clamp-4 block font-mono text-xs whitespace-pre-wrap text-zinc-800 dark:text-zinc-200">{{ entry.sql }}</span>
                        <span v-if="entry.error" class="mt-1 block text-xs text-red-700 dark:text-red-300">{{ entry.error }}</span>
                    </button>
                </li>
            </ul>
        </div>

        <footer v-if="history && (page > 1 || history.meta.has_more)" class="flex items-center gap-2 border-t border-zinc-200 px-4 py-2 text-sm dark:border-zinc-800">
            <button type="button" class="dbx-btn" :disabled="page <= 1 || loading" @click="goTo(page - 1)">← Newer</button>
            <span class="text-zinc-500">Page {{ page }}</span>
            <button type="button" class="dbx-btn" :disabled="!history.meta.has_more || loading" @click="goTo(page + 1)">Older →</button>
        </footer>
    </div>
</template>
