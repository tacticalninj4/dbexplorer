<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { describeError } from '../api';
import { formatDate } from '../composables/format';
import { cancelRun, downloadRun, isActive, runDuration, runType, watchRun } from '../composables/runs';
import type { BackgroundRun } from '../composables/runs';
import BaseIcon from './BaseIcon.vue';
import RunStatusBadge from './RunStatusBadge.vue';

/**
 * One background run, followed live: it polls the run every 2 s while it is
 * queued or running, and offers Cancel, Download (once a result file exists)
 * and a link to the Runs page. Emits every fresh copy as `update`, so the
 * screen that queued it can show more (script statements, import counts).
 */
const props = withDefaults(defineProps<{ label: string; run: BackgroundRun; title?: string; compact?: boolean }>(), { title: undefined, compact: false });
const emit = defineEmits<{ update: [run: BackgroundRun] }>();

const current = ref<BackgroundRun>(props.run);
const error = ref<string | null>(null);
const busy = ref(false);
let stop: (() => void) | undefined;

function follow(run: BackgroundRun): void {
    stop?.();
    current.value = run;
    error.value = null;
    stop = watchRun(
        props.label,
        run.id,
        (fresh) => {
            current.value = fresh;
            error.value = null;
            emit('update', fresh);
        },
        (e) => (error.value = describeError(e, 'Lost track of the run. Retrying…')),
    );
}

watch(() => props.run.id, () => follow(props.run), { immediate: true });
onBeforeUnmount(() => stop?.());

const active = computed(() => isActive(current.value));
const type = computed(() => runType(current.value.type));
const duration = computed(() => runDuration(current.value));

async function cancel(): Promise<void> {
    busy.value = true;
    try {
        current.value = await cancelRun(props.label, current.value.id);
        emit('update', current.value);
        follow(current.value);
    } catch (e) {
        error.value = describeError(e);
    } finally {
        busy.value = false;
    }
}

async function download(): Promise<void> {
    try {
        await downloadRun(props.label, current.value.id);
    } catch (e) {
        error.value = describeError(e, 'The file could not be downloaded.');
    }
}
</script>

<template>
    <div class="min-w-0" :class="compact ? 'space-y-2' : 'dbx-card space-y-3 p-3'">
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
            <span class="flex min-w-0 items-center gap-2" :class="compact ? 'basis-full' : ''">
                <BaseIcon :name="type.icon" class="text-fg-subtle" />
                <span class="min-w-0 truncate font-medium" :title="title ?? type.title">{{ title ?? type.title }} <span class="text-fg-subtle tabular-nums">#{{ current.id }}</span></span>
            </span>
            <RunStatusBadge :run="current" />
            <span v-if="duration" class="inline-flex items-center gap-1 text-xs text-fg-muted tabular-nums"><BaseIcon name="clock" :size="12" />{{ duration }}</span>
            <span v-else-if="!compact" class="text-xs text-fg-muted" :title="current.created_at">Queued {{ formatDate(current.created_at) }}</span>
        </div>

        <div v-if="active" class="flex items-center gap-2" role="status">
            <span
                v-if="current.progress !== null && current.progress > 0"
                class="h-1.5 w-full max-w-64 overflow-hidden rounded-full bg-sunken"
                role="progressbar"
                :aria-valuenow="current.progress"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-label="`Run ${current.id} progress`"
            >
                <span class="block h-full rounded-full bg-accent-solid transition-[width] duration-200 ease-pop motion-reduce:transition-none" :style="{ width: `${current.progress}%` }" />
            </span>
            <span v-else class="dbx-spinner" aria-hidden="true" />
            <span class="text-xs text-fg-muted tabular-nums">
                <template v-if="current.status === 'queued'">Waiting for a queue worker…</template>
                <template v-else-if="current.progress">{{ current.progress }}%</template>
                <template v-else>Working…</template>
            </span>
        </div>

        <div v-if="current.error" class="dbx-callout dbx-callout-danger" role="alert">
            <BaseIcon name="alert-circle" />
            <pre class="min-w-0 font-sans break-words whitespace-pre-wrap">{{ current.error }}</pre>
        </div>
        <p v-if="error" class="text-xs text-danger-fg" role="alert">{{ error }}</p>

        <slot :run="current" />

        <div class="flex flex-wrap items-center gap-1.5">
            <button v-if="active" type="button" class="dbx-btn dbx-btn-sm dbx-btn-danger-ghost" :disabled="busy || current.cancel_requested" @click="cancel">
                <BaseIcon name="stop" />{{ current.cancel_requested ? 'Cancelling…' : 'Cancel' }}
            </button>
            <button v-if="current.result_available" type="button" class="dbx-btn dbx-btn-sm" :class="compact ? 'dbx-btn-primary' : ''" @click="download"><BaseIcon name="download" />Download</button>
            <router-link :to="{ name: 'runs', params: { label }, query: { run: String(current.id) } }" class="dbx-btn dbx-btn-sm dbx-btn-ghost">View in Runs<BaseIcon name="arrow-right" /></router-link>
        </div>
    </div>
</template>
