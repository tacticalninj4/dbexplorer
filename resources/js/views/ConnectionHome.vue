<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { describeError } from '../api';
import * as catalog from '../composables/catalog';
import { findConnection } from '../composables/session';
import type { Extension, Schema } from '../types';

// /:label: what this connection offers.
const route = useRoute();
const label = computed(() => String(route.params.label));
const connection = computed(() => findConnection(label.value));

const schemas = ref<Schema[] | null>(null);
const extensions = ref<Extension[]>([]);
const error = ref<string | null>(null);

watch(
    label,
    async (value) => {
        schemas.value = null;
        error.value = null;
        try {
            [schemas.value, extensions.value] = await Promise.all([catalog.schemas(value), catalog.extensions(value).catch(() => [])]);
        } catch (e) {
            error.value = describeError(e);
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="overflow-auto p-6">
        <h1 class="text-lg font-semibold">{{ label }}</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            You are <strong class="font-medium text-zinc-700 dark:text-zinc-200">{{ connection?.role }}</strong> on this connection.
            Pick a table in the tree, or
            <router-link :to="{ name: 'sql', params: { label } }" class="dbx-link">open the SQL editor</router-link>.
        </p>

        <p v-if="error" role="alert" class="mt-4 text-sm text-red-700 dark:text-red-300">{{ error }}</p>
        <p v-else-if="schemas === null" class="mt-4 text-sm text-zinc-500">Loading…</p>
        <template v-else>
            <h2 class="mt-6 mb-2 text-sm font-semibold">Schemas</h2>
            <table class="dbx-table">
                <thead><tr><th>Name</th><th>Owner</th><th>Comment</th></tr></thead>
                <tbody>
                    <tr v-for="schema in schemas" :key="schema.name">
                        <td class="font-medium">{{ schema.name }}</td>
                        <td>{{ schema.owner }}</td>
                        <td class="text-zinc-500">{{ schema.comment ?? '' }}</td>
                    </tr>
                </tbody>
            </table>

            <template v-if="extensions.length">
                <h2 class="mt-6 mb-2 text-sm font-semibold">Extensions</h2>
                <table class="dbx-table">
                    <thead><tr><th>Name</th><th>Version</th><th>Schema</th><th>Comment</th></tr></thead>
                    <tbody>
                        <tr v-for="extension in extensions" :key="extension.name">
                            <td class="font-medium">{{ extension.name }}</td>
                            <td>{{ extension.version }}</td>
                            <td>{{ extension.schema }}</td>
                            <td class="text-zinc-500">{{ extension.comment ?? '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </template>
    </div>
</template>
