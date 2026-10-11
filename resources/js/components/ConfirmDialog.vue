<script setup lang="ts">
import BaseIcon from './BaseIcon.vue';
import ModalDialog from './ModalDialog.vue';

/**
 * A confirmation that names the action and states its consequence. Cancel
 * has focus; danger styling only when something is lost (UI_AUDIT §1 #2).
 * The default slot adds detail (the SQL, the session) under the callout.
 */
withDefaults(
    defineProps<{ open: boolean; title: string; message: string; action: string; danger?: boolean; warning?: boolean; busy?: boolean; error?: string | null; dismiss?: string }>(),
    { danger: false, warning: false, busy: false, error: null, dismiss: 'Cancel' },
);
const emit = defineEmits<{ confirm: []; cancel: [] }>();
</script>

<template>
    <ModalDialog :open="open" :title="title" @close="emit('cancel')">
        <div class="space-y-3">
            <div class="dbx-callout" :class="danger ? 'dbx-callout-danger' : warning ? 'dbx-callout-warning' : 'dbx-callout-info'">
                <BaseIcon :name="danger || warning ? 'alert' : 'info'" />
                <span>{{ message }}</span>
            </div>
            <slot />
            <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><span>{{ error }}</span></div>
        </div>
        <div class="mt-4 flex items-center justify-end gap-2 border-t border-line pt-4">
            <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" autofocus @click="emit('cancel')">{{ dismiss }}</button>
            <button type="button" class="dbx-btn" :class="danger ? 'dbx-btn-danger' : 'dbx-btn-primary'" :disabled="busy" @click="emit('confirm')">
                <span v-if="busy" class="dbx-spinner border-current/30 border-t-current" aria-hidden="true" />
                <BaseIcon v-else :name="danger ? 'alert' : 'check'" />{{ action }}
            </button>
        </div>
    </ModalDialog>
</template>
