<script setup lang="ts">
import { defineAsyncComponent, reactive, watch } from 'vue';
import { useRouter } from 'vue-router';
import { invalidate } from '../composables/catalog';
import { createRequest, setFlash } from '../composables/ddl';
import type { CreatedObject } from './CreateTableForm.vue';

// Loaded on first use, then kept mounted so the dialogs animate out.
const CreateTableForm = defineAsyncComponent(() => import('./CreateTableForm.vue'));
const CreateViewForm = defineAsyncComponent(() => import('./CreateViewForm.vue'));
const used = reactive({ table: false, view: false });

/**
 * The create-table and create-view dialogs of one connection, opened with
 * openCreate() from the tree's schema menu or the overview. On success the
 * catalog (and so the tree) reloads, and the new object opens.
 */
const props = defineProps<{ label: string }>();
const router = useRouter();

watch(createRequest, (request) => {
    if (request) {
        used[request.kind === 'table' ? 'table' : 'view'] = true;
    }
}, { immediate: true });

const KINDS = { table: 'table', view: 'view', materialized_view: 'materialized view' } as const;

function onClose(created: CreatedObject | null): void {
    const kind = createRequest.value?.kind ?? 'table';
    createRequest.value = null;

    if (!created) {
        return;
    }

    invalidate(props.label);

    if (created.run !== undefined) {
        setFlash(`Creating ${created.schema}.${created.name} was queued as background run #${created.run}.`, created.run);
        void router.push({ name: 'runs', params: { label: props.label } });
        return;
    }

    setFlash(`Created ${KINDS[kind]} ${created.schema}.${created.name}.`);
    void router.push({ name: 'object', params: { label: props.label, schema: created.schema, name: created.name }, query: { tab: kind === 'table' ? 'structure' : 'data' } });
}
</script>

<template>
    <CreateTableForm v-if="used.table" :open="createRequest?.kind === 'table'" :label="label" :schema="createRequest?.schema ?? 'public'" @close="onClose" />
    <CreateViewForm
        v-if="used.view"
        :open="createRequest !== null && createRequest.kind !== 'table'"
        :label="label"
        :schema="createRequest?.schema ?? 'public'"
        :initial-kind="createRequest?.kind === 'materialized_view' ? 'materialized_view' : 'view'"
        @close="onClose"
    />
</template>
