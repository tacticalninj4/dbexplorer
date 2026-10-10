<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BaseIcon from './components/BaseIcon.vue';
import PasswordConfirmModal from './components/PasswordConfirmModal.vue';
import RoleBadge from './components/RoleBadge.vue';
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
const userName = computed(() => me.value?.user.name ?? user.name);
const initials = computed(() =>
    userName.value
        .split(/\s+/)
        .filter((part) => part !== '')
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join(''),
);
</script>

<template>
    <div class="flex h-screen flex-col">
        <header class="flex h-11 shrink-0 items-center gap-3 border-b border-line bg-panel px-3">
            <router-link to="/" class="flex items-center gap-2 rounded-md px-1 py-0.5 text-sm font-semibold tracking-tight">
                <span class="grid size-6 place-items-center rounded-md bg-accent-solid text-on-accent"><BaseIcon name="database" :size="14" /></span>
                DbExplorer
            </router-link>

            <span class="text-fg-subtle select-none" aria-hidden="true">/</span>

            <div class="flex min-w-0 items-center gap-2">
                <label for="dbx-connection" class="sr-only">Connection</label>
                <select
                    id="dbx-connection"
                    v-model="selected"
                    :disabled="connections.length === 0"
                    class="dbx-select max-w-56 font-medium"
                    title="Connection"
                >
                    <option v-if="connections.length === 0" value="">No connections</option>
                    <option v-else-if="!current" value="" disabled>Choose a connection…</option>
                    <option v-for="connection in connections" :key="connection.label" :value="connection.label">
                        {{ connection.label }}
                    </option>
                </select>
                <RoleBadge v-if="current" :role="current.role" />
            </div>

            <span class="ml-auto flex min-w-0 items-center gap-2 text-sm text-fg-muted">
                <span class="truncate">{{ userName }}</span>
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-sunken text-[11px] font-semibold text-fg-muted ring-1 ring-line" aria-hidden="true">{{ initials }}</span>
            </span>
        </header>

        <div v-if="error" role="alert" class="dbx-banner border-danger-line bg-danger-bg text-danger-fg">
            <BaseIcon name="alert-circle" />
            <span>{{ error }}</span>
        </div>
        <div
            v-for="(warning, index) in me?.warnings ?? []"
            :key="index"
            role="status"
            class="dbx-banner border-warning-line bg-warning-bg text-warning-fg"
        >
            <BaseIcon name="alert" />
            <span><span class="sr-only">Warning: </span>{{ warning }}</span>
        </div>

        <main class="flex min-h-0 flex-1 flex-col">
            <router-view />
        </main>

        <PasswordConfirmModal />
    </div>
</template>
