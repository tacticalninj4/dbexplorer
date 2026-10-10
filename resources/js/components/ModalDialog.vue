<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import BaseIcon from './BaseIcon.vue';

/**
 * An in-page modal on the native <dialog>: focus is trapped and Esc closes
 * it. Never window.confirm/prompt.
 */
const props = withDefaults(defineProps<{ open: boolean; title: string; description?: string; wide?: boolean }>(), { description: undefined, wide: false });
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

// After the DOM update, so slot content (and its [autofocus]) exists when it opens.
watch(() => props.open, sync, { flush: 'post' });
onMounted(() => sync(props.open));
</script>

<template>
    <dialog
        ref="dialog"
        class="relative m-auto max-h-[85vh] w-[calc(100%-2rem)] overflow-y-auto rounded-lg border border-line bg-panel p-0 text-fg shadow-dialog backdrop:bg-black/40"
        :class="wide ? 'max-w-3xl' : 'max-w-md'"
        :aria-label="title"
        @cancel.prevent="emit('close')"
    >
        <div class="px-5 pt-4 pr-12">
            <h2 class="text-base font-semibold break-words">{{ title }}</h2>
            <p v-if="description" class="mt-0.5 text-sm text-fg-muted">{{ description }}</p>
        </div>
        <div class="px-5 pt-3 pb-5">
            <slot />
        </div>
        <!-- Last in the DOM, so opening focuses the first field or [autofocus], not Close. -->
        <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-icon dbx-btn-sm absolute top-3.5 right-3.5" aria-label="Close" @click="emit('close')">
            <BaseIcon name="x" />
        </button>
    </dialog>
</template>

<style scoped>
/* The dialog is a container, not a control: its children show focus. */
dialog:focus-visible {
    outline: none;
}

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
