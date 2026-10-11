<script setup lang="ts">
import { dismissRun, runToasts } from '../composables/runs';
import BaseIcon from './BaseIcon.vue';
import RunPanel from './RunPanel.vue';

/**
 * Runs queued from a dialog that has closed (exports, background queries
 * from the grid): each toast follows its run until it finishes, then offers
 * the download. They stay until dismissed; the Runs page has the full list.
 */
</script>

<template>
    <section aria-label="Background runs" class="pointer-events-none fixed right-4 bottom-4 z-40 flex w-88 max-w-[calc(100vw-2rem)] flex-col gap-2">
        <TransitionGroup name="dbx-pop">
            <div
                v-for="toast in runToasts"
                :key="toast.run.id"
                role="status"
                class="pointer-events-auto relative rounded-lg border border-line bg-panel p-3 pr-10 text-sm shadow-dialog"
            >
                <RunPanel :label="toast.label" :run="toast.run" :title="toast.title" compact />
                <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-icon dbx-btn-sm absolute top-2.5 right-2.5" :aria-label="`Dismiss run ${toast.run.id}`" title="Dismiss" @click="dismissRun(toast.run.id)">
                    <BaseIcon name="x" />
                </button>
            </div>
        </TransitionGroup>
    </section>
</template>
