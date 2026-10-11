<script setup lang="ts">
import { ref, watch } from 'vue';
import { ddlFailure, executeDdl, previewDdl } from '../composables/ddl';
import type { DdlFailure, DdlOp } from '../composables/ddl';
import type { DdlPreview } from '../types';
import BaseIcon from './BaseIcon.vue';
import DdlPreviewPanel from './DdlPreviewPanel.vue';
import ModalDialog from './ModalDialog.vue';

/**
 * REFRESH MATERIALIZED VIEW (admins only), optionally CONCURRENTLY, which
 * doesn't lock out readers but needs a unique index. When the server queues
 * it as a background run (202), the page shows the queued-run notice.
 */
const props = defineProps<{ open: boolean; label: string; schema: string; name: string }>();
const emit = defineEmits<{ close: [done: { title: string; run?: number } | null] }>();

const concurrently = ref(false);
const preview = ref<DdlPreview | null>(null);
const failure = ref<DdlFailure | null>(null);
const busy = ref(false);

watch(() => props.open, (open) => {
    if (open) {
        concurrently.value = false;
        preview.value = null;
        failure.value = null;
    }
});
watch(concurrently, () => (preview.value = null));

function operation(): DdlOp {
    return { op: 'refresh_materialized_view', schema: props.schema, name: props.name, ...(concurrently.value ? { concurrently: true } : {}) };
}

async function build(): Promise<void> {
    failure.value = null;
    preview.value = null;
    busy.value = true;

    try {
        preview.value = await previewDdl(props.label, operation());
    } catch (e) {
        failure.value = ddlFailure(e);
    } finally {
        busy.value = false;
    }
}

async function execute(): Promise<void> {
    failure.value = null;
    busy.value = true;

    try {
        const result = await executeDdl(props.label, operation());
        emit('close', { title: `Refresh ${props.schema}.${props.name}`, run: result.run });
    } catch (e) {
        failure.value = ddlFailure(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <ModalDialog :open="open" :title="`Refresh ${schema}.${name}`" description="Re-runs the view's query and replaces its stored rows." @close="emit('close', null)">
        <form class="space-y-4" @submit.prevent="build">
            <label class="dbx-check items-start">
                <input v-model="concurrently" type="checkbox" class="mt-0.5">
                <span>Concurrently <span class="block text-xs text-fg-subtle">Readers aren't locked out while it refreshes. Needs a unique index on the view.</span></span>
            </label>

            <div v-if="failure" role="alert" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><span>{{ failure.message }}</span></div>

            <DdlPreviewPanel v-if="preview" :preview="preview" />

            <div class="flex items-center justify-end gap-2 border-t border-line pt-4">
                <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" @click="emit('close', null)">Cancel</button>
                <button type="submit" class="dbx-btn" :class="preview ? '' : 'dbx-btn-primary'" :disabled="busy">
                    <span v-if="busy && !preview" class="dbx-spinner" aria-hidden="true" />{{ preview ? 'Refresh preview' : 'Preview SQL' }}
                </button>
                <button v-if="preview" type="button" class="dbx-btn dbx-btn-primary" :disabled="busy" @click="execute">
                    <span v-if="busy" class="dbx-spinner border-white/40 border-t-white" aria-hidden="true" />
                    <BaseIcon v-else name="refresh" />Refresh view
                </button>
            </div>
        </form>
    </ModalDialog>
</template>
