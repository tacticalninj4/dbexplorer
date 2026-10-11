<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import BaseIcon from './BaseIcon.vue';

/**
 * A side panel on the native <dialog> (focus trapped, Esc closes), sliding
 * in from the right edge: details of a list row without leaving the list.
 */
const props = withDefaults(defineProps<{ open: boolean; title: string; description?: string }>(), { description: undefined });
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

/** A click on the backdrop (the dialog box itself, outside the panel) closes it. */
function onClick(event: MouseEvent): void {
    if (event.target === dialog.value) {
        emit('close');
    }
}

watch(() => props.open, sync, { flush: 'post' });
onMounted(() => sync(props.open));
</script>

<template>
    <dialog
        ref="dialog"
        class="fixed inset-y-0 right-0 left-auto m-0 h-full max-h-none w-[min(40rem,100vw)] max-w-none overflow-hidden border-l border-line bg-panel p-0 text-fg shadow-dialog backdrop:bg-black/30"
        :aria-label="title"
        @cancel.prevent="emit('close')"
        @click="onClick"
    >
        <div class="flex h-full flex-col">
            <div class="flex shrink-0 items-start gap-3 border-b border-line px-5 py-3">
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-semibold break-words">{{ title }}</h2>
                    <p v-if="description" class="mt-0.5 text-sm text-fg-muted">{{ description }}</p>
                </div>
                <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-icon dbx-btn-sm" aria-label="Close" @click="emit('close')"><BaseIcon name="x" /></button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                <slot />
            </div>
        </div>
    </dialog>
</template>

<style scoped>
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

dialog:not([open]) {
    opacity: 0;
    transform: translateX(24px);
}

@starting-style {
    dialog[open] {
        opacity: 0;
        transform: translateX(24px);
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
