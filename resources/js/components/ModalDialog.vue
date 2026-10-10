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

<style scoped>
dialog {
    transition:
        opacity 180ms cubic-bezier(0.23, 1, 0.32, 1),
        transform 180ms cubic-bezier(0.23, 1, 0.32, 1),
        display 180ms allow-discrete,
        overlay 180ms allow-discrete;
}

/* Exit state (also the exit target for the backdrop fade below). */
dialog:not([open]) {
    opacity: 0;
    transform: translateY(6px) scale(0.97);
}

/* Enter start: never scale from 0 — a barely-visible shape feels physical. */
@starting-style {
    dialog[open] {
        opacity: 0;
        transform: translateY(6px) scale(0.97);
    }

    dialog[open]::backdrop {
        opacity: 0;
    }
}

dialog::backdrop {
    transition:
        opacity 180ms ease,
        display 180ms allow-discrete,
        overlay 180ms allow-discrete;
}

dialog:not([open])::backdrop {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    dialog,
    dialog::backdrop {
        transition-property: opacity, display, overlay;
    }

    dialog:not([open]) {
        transform: none;
    }
}
</style>
