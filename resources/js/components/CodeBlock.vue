<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';

// Read-only SQL or text with a copy button. Text interpolation only (S15).
const props = withDefaults(defineProps<{ code: string; copyLabel?: string }>(), { copyLabel: 'Copy' });

const state = ref<'idle' | 'copied' | 'failed'>('idle');
const pre = ref<HTMLElement | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.code);
        state.value = 'copied';
    } catch {
        // No clipboard access (insecure origin, permissions): select it instead.
        const selection = window.getSelection();
        if (pre.value && selection) {
            selection.selectAllChildren(pre.value);
        }
        state.value = 'failed';
    }

    clearTimeout(timer);
    timer = setTimeout(() => (state.value = 'idle'), 2000);
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="relative">
        <button type="button" class="dbx-btn absolute top-2 right-2" @click="copy">
            {{ state === 'copied' ? 'Copied' : state === 'failed' ? 'Press Ctrl+C' : copyLabel }}
        </button>
        <span class="sr-only" aria-live="polite">{{ state === 'copied' ? 'Copied to clipboard' : '' }}</span>
        <pre ref="pre" class="overflow-auto rounded-md border border-zinc-200 bg-white p-3 pr-24 font-mono text-xs leading-relaxed whitespace-pre dark:border-zinc-800 dark:bg-zinc-900">{{ code }}</pre>
    </div>
</template>
