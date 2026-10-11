<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { describeError } from '../api';
import EmptyState from '../components/EmptyState.vue';
import BaseIcon from '../components/BaseIcon.vue';
import RoleBadge from '../components/RoleBadge.vue';
import SkeletonRows from '../components/SkeletonRows.vue';
import * as catalog from '../composables/catalog';
import { openCreate } from '../composables/ddl';
import { findConnection } from '../composables/session';
import type { Extension, Schema } from '../types';

// /:label: what this connection offers.
const route = useRoute();
const label = computed(() => String(route.params.label));
const connection = computed(() => findConnection(label.value));
/** Set by the object page after a successful DROP TABLE (F25b). */
const dropped = computed(() => (typeof route.query.dropped === 'string' ? route.query.dropped : null));

const ROLE_SUMMARY = {
    viewer: 'You can browse objects and data, and run read-only SQL.',
    editor: 'You can browse, edit rows, and run read and write (DML) SQL.',
    admin: 'You can browse, edit rows, change the schema, and run any SQL. Destructive statements always ask you to confirm first.',
} as const;

const schemas = ref<Schema[] | null>(null);

async function load(value: string): Promise<void> {
    schemas.value = null;
    error.value = null;
    try {
        [schemas.value, extensions.value] = await Promise.all([catalog.schemas(value), catalog.extensions(value).catch(() => [])]);
    } catch (e) {
        error.value = describeError(e);
    }
}
const extensions = ref<Extension[]>([]);
const error = ref<string | null>(null);

watch(label, load, { immediate: true });
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="dbx-page-header">
            <BaseIcon name="database" class="text-fg-subtle" />
            <h1 class="text-lg font-semibold">{{ label }}</h1>
            <RoleBadge v-if="connection" :role="connection.role" />
            <router-link :to="{ name: 'sql', params: { label } }" class="dbx-btn dbx-btn-primary ml-auto"><BaseIcon name="terminal" />Open SQL editor</router-link>
            <p v-if="connection" class="w-full text-sm text-fg-muted">{{ ROLE_SUMMARY[connection.role] }} Pick an object in the tree to see its data and structure.</p>
        </header>

        <div v-if="dropped" role="status" class="dbx-banner border-success-line bg-success-bg text-success-fg">
            <BaseIcon name="check-circle" />
            <span>Dropped {{ dropped }}.</span>
        </div>

        <div class="min-h-0 flex-1 overflow-auto p-4">
            <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger max-w-3xl">
                <BaseIcon name="alert-circle" />
                <span class="flex-1">{{ error }}</span>
                <button type="button" class="dbx-btn dbx-btn-sm" @click="load(label)"><BaseIcon name="refresh" />Retry</button>
            </div>
            <div v-else-if="schemas === null" class="dbx-card max-w-4xl"><SkeletonRows :rows="3" :columns="3" /></div>
            <template v-else>
                <section aria-labelledby="dbx-schemas-heading" class="max-w-4xl">
                    <h2 id="dbx-schemas-heading" class="mb-2 flex items-center gap-2 text-sm font-semibold">
                        Schemas <span class="text-xs font-normal text-fg-subtle tabular-nums">{{ schemas.length }}</span>
                    </h2>
                    <EmptyState v-if="schemas.length === 0" icon="schema" title="No schemas you can access" />
                    <div v-else class="dbx-card overflow-x-auto">
                        <table class="dbx-table">
                            <thead><tr><th>Name</th><th>Owner</th><th>Comment</th><th v-if="connection?.role === 'admin'"><span class="sr-only">Actions</span></th></tr></thead>
                            <tbody>
                                <tr v-for="schema in schemas" :key="schema.name">
                                    <td class="font-medium"><span class="inline-flex items-center gap-2"><BaseIcon name="schema" :size="14" class="text-fg-subtle" />{{ schema.name }}</span></td>
                                    <td class="text-fg-muted">{{ schema.owner }}</td>
                                    <td class="text-fg-muted">{{ schema.comment ?? '' }}</td>
                                    <td v-if="connection?.role === 'admin'" class="w-px whitespace-nowrap">
                                        <span class="flex justify-end gap-1">
                                            <button type="button" class="dbx-btn dbx-btn-sm" :aria-label="`New table in ${schema.name}`" @click="openCreate('table', schema.name)"><BaseIcon name="plus" />Table</button>
                                            <button type="button" class="dbx-btn dbx-btn-sm" :aria-label="`New view in ${schema.name}`" @click="openCreate('view', schema.name)"><BaseIcon name="plus" />View</button>
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section v-if="extensions.length" aria-labelledby="dbx-extensions-heading" class="mt-6 max-w-4xl">
                    <h2 id="dbx-extensions-heading" class="mb-2 flex items-center gap-2 text-sm font-semibold">
                        Extensions <span class="text-xs font-normal text-fg-subtle tabular-nums">{{ extensions.length }}</span>
                    </h2>
                    <div class="dbx-card overflow-x-auto">
                        <table class="dbx-table">
                            <thead><tr><th>Name</th><th>Version</th><th>Schema</th><th>Comment</th></tr></thead>
                            <tbody>
                                <tr v-for="extension in extensions" :key="extension.name">
                                    <td class="font-medium"><span class="inline-flex items-center gap-2"><BaseIcon name="extension" :size="14" class="text-fg-subtle" />{{ extension.name }}</span></td>
                                    <td class="font-mono text-xs text-fg-muted">{{ extension.version }}</td>
                                    <td class="text-fg-muted">{{ extension.schema }}</td>
                                    <td class="text-fg-muted">{{ extension.comment ?? '' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
        </div>
    </div>
</template>
