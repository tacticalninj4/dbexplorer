<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';

/**
 * An in-page modal on the native <dialog>: focus is trapped and Esc closes
 * it. Never window.confirm/prompt.
 */
const props = withDefaults(defineProps<{ open: boolean; title: string; wide?: boolean }>(), { wide: false });
const emit = defineEmits<{ close: [] }>();

const dialog = ref<HTMLDialogElement | null>(null);

function sync(open: boolean): void {
    const el = dialog.value;
    if (!el) {
        return;
    }

    if (open && !el.open) {
        el.showModal();
    } else if (!open && el.open) {
        el.close();
    }
}

watch(() => props.open, sync);
onMounted(() => sync(props.open));
</script>

<template>
    <dialog
        ref="dialog"
        class="m-auto w-full max-h-[85vh] overflow-y-auto rounded-lg border border-zinc-200 bg-white p-0 text-zinc-900 shadow-xl backdrop:bg-zinc-950/40 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
        :class="wide ? 'max-w-3xl' : 'max-w-md'"
        :aria-label="title"
        @cancel.prevent="emit('close')"
    >
        <div class="p-5">
            <h2 class="text-base font-semibold">{{ title }}</h2>
            <slot />
        </div>
    </dialog>
</template>
