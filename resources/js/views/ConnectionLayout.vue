<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import ObjectTree from '../components/ObjectTree.vue';
import { findConnection, useSession } from '../composables/session';

// /:label/…: the object tree beside the selected view.
const route = useRoute();
const { me } = useSession();

const label = computed(() => String(route.params.label));
const connection = computed(() => (me.value ? findConnection(label.value) : undefined));

const links = computed(() => [
    { name: 'connection', title: 'Overview' },
    { name: 'sql', title: 'SQL editor' },
    { name: 'history', title: 'History' },
    { name: 'runs', title: 'Runs' },
]);
</script>

<template>
    <p v-if="!me" class="p-6 text-sm text-zinc-500">Loading…</p>
    <div v-else-if="!connection" class="flex flex-1 items-center justify-center p-8">
        <div class="max-w-sm text-center">
            <h1 class="text-lg font-medium">Connection not available</h1>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                There is no connection named “{{ label }}” that you can use. Pick one from the header.
            </p>
        </div>
    </div>
    <div v-else class="flex min-h-0 flex-1">
        <aside class="flex w-72 shrink-0 flex-col border-r border-zinc-200 bg-white max-lg:w-60 dark:border-zinc-800 dark:bg-zinc-900">
            <nav aria-label="Connection" class="flex gap-1 border-b border-zinc-200 p-2 text-sm dark:border-zinc-800">
                <router-link
                    v-for="link in links"
                    :key="link.name"
                    :to="{ name: link.name, params: { label } }"
                    class="rounded px-2 py-1 hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none dark:hover:bg-zinc-800"
                    exact-active-class="bg-sky-100 font-medium text-sky-900 dark:bg-sky-950 dark:text-sky-100"
                >
                    {{ link.title }}
                </router-link>
            </nav>
            <ObjectTree :key="label" :label="label" />
        </aside>
        <div class="flex min-w-0 flex-1 flex-col">
            <router-view :key="label" />
        </div>
    </div>
</template>
