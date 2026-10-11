<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import EmptyState from '../components/EmptyState.vue';
import BaseIcon from '../components/BaseIcon.vue';
import type { IconName } from '../components/BaseIcon.vue';
import ObjectTree from '../components/ObjectTree.vue';
import { findConnection, useSession } from '../composables/session';

// /:label/…: the object tree beside the selected view.
const route = useRoute();
const { me } = useSession();

const label = computed(() => String(route.params.label));
const connection = computed(() => (me.value ? findConnection(label.value) : undefined));

const links: { name: string; title: string; icon: IconName }[] = [
    { name: 'connection', title: 'Overview', icon: 'database' },
    { name: 'sql', title: 'SQL editor', icon: 'terminal' },
    { name: 'history', title: 'Query history', icon: 'history' },
    { name: 'runs', title: 'Runs', icon: 'activity' },
];

// batch 4B: automation and ops screens. Admin-only items are hidden for
// other roles; the API enforces the role either way.
const opsLinks = computed(() =>
    [
        { name: 'queries', title: 'Saved queries', icon: 'bookmark' as IconName, admin: false },
    ].filter((link) => !link.admin || connection.value?.role === 'admin'),
);
// end batch 4B
</script>

<template>
    <div v-if="!me" class="flex flex-1 items-center justify-center gap-2 text-sm text-fg-muted" role="status">
        <span class="dbx-spinner" aria-hidden="true" />Loading…
    </div>
    <div v-else-if="!connection" class="flex flex-1 items-center justify-center">
        <EmptyState icon="ban" title="Connection not available">
            There is no connection named “{{ label }}” that you can use. Pick another one from the header.
        </EmptyState>
    </div>
    <div v-else class="flex min-h-0 flex-1">
        <aside class="flex w-68 shrink-0 flex-col border-r border-line bg-panel max-lg:w-60">
            <nav aria-label="Connection" class="flex flex-col gap-px border-b border-line p-2">
                <router-link
                    v-for="link in [...links, ...opsLinks]"
                    :key="link.name"
                    :to="{ name: link.name, params: { label } }"
                    class="flex h-7 items-center gap-2.5 rounded-md px-2 text-sm text-fg-muted transition-colors duration-100 hover:bg-hover hover:text-fg"
                    exact-active-class="bg-accent-soft! font-medium text-accent-soft-fg!"
                >
                    <BaseIcon :name="link.icon" />
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
