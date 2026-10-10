<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, describeError, segment } from '../api';
import ClassificationBadge from '../components/ClassificationBadge.vue';
import EmptyState from '../components/EmptyState.vue';
import BaseIcon from '../components/BaseIcon.vue';
import SkeletonRows from '../components/SkeletonRows.vue';
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

const succeeded = (outcome: string) => outcome === 'success';
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="dbx-page-header">
            <BaseIcon name="history" class="text-fg-subtle" />
            <h1 class="text-lg font-semibold">Query history</h1>
            <span class="text-sm text-fg-muted">Your queries on {{ label }}, newest first. Pick one to open it in a new editor tab.</span>
        </header>

        <div class="min-h-0 flex-1 overflow-auto p-4">
            <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger max-w-4xl"><BaseIcon name="alert-circle" />{{ error }}</div>
            <div v-else-if="loading && !history" class="dbx-card max-w-4xl"><SkeletonRows :rows="5" :columns="2" /></div>
            <EmptyState v-else-if="history && history.data.length === 0" icon="history" title="No queries yet">
                Statements you run in the SQL editor appear here, with their outcome and duration.
                <template #actions>
                    <router-link :to="{ name: 'sql', params: { label } }" class="dbx-btn"><BaseIcon name="terminal" />Open SQL editor</router-link>
                </template>
            </EmptyState>
            <ul v-else-if="history" class="dbx-card max-w-4xl divide-y divide-line-subtle overflow-hidden" :aria-busy="loading">
                <li v-for="entry in history.data" :key="entry.id">
                    <button
                        type="button"
                        class="group block w-full cursor-default px-3.5 py-2.5 text-left transition-colors duration-100 outline-offset-[-2px] hover:bg-hover"
                        :title="'Open in the SQL editor'"
                        @click="open(entry.sql)"
                    >
                        <span class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-fg-muted">
                            <span class="inline-flex items-center gap-1 font-medium" :class="succeeded(entry.outcome) ? 'text-success-fg' : 'text-danger-fg'">
                                <BaseIcon :name="succeeded(entry.outcome) ? 'check-circle' : 'x-circle'" :size="12" />{{ entry.outcome }}
                            </span>
                            <ClassificationBadge :value="entry.classification" />
                            <span class="tabular-nums">{{ formatDate(entry.created_at) }}</span>
                            <span class="tabular-nums">{{ formatDuration(entry.duration_ms) }}</span>
                            <span v-if="entry.row_count !== null" class="tabular-nums">{{ formatNumber(entry.row_count) }} {{ entry.row_count === 1 ? 'row' : 'rows' }}</span>
                            <span>as {{ entry.role }}</span>
                            <span class="ml-auto inline-flex items-center gap-1 text-accent opacity-0 transition-opacity duration-100 group-hover:opacity-100 group-focus-visible:opacity-100">Open<BaseIcon name="arrow-right" :size="12" /></span>
                        </span>
                        <span class="mt-1.5 line-clamp-4 block font-mono text-xs whitespace-pre-wrap text-fg">{{ entry.sql }}</span>
                        <span v-if="entry.error" class="mt-1.5 block font-mono text-xs text-danger-fg">{{ entry.error }}</span>
                    </button>
                </li>
            </ul>
        </div>

        <footer v-if="history && (page > 1 || history.meta.has_more)" class="dbx-statusbar">
            <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-sm" :disabled="page <= 1 || loading" @click="goTo(page - 1)"><BaseIcon name="chevron-left" />Newer</button>
            <span>Page {{ page }}</span>
            <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-sm" :disabled="!history.meta.has_more || loading" @click="goTo(page + 1)">Older<BaseIcon name="chevron-right" /></button>
        </footer>
    </div>
</template>
