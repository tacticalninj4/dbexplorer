<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref } from 'vue';
import type { PasswordConfirmEvent } from '../api';
import { api, ApiError, apiEvents, describeError } from '../api';
import ModalDialog from './ModalDialog.vue';

/**
 * S5: a destructive request answered 423. Ask for the password, POST it to
 * api/confirm-password, and let api() retry the original request.
 */
const open = ref(false);
const password = ref('');
const error = ref<string | null>(null);
const busy = ref(false);
const input = ref<HTMLInputElement | null>(null);
let settle: ((confirmed: boolean) => void) | null = null;

function onRequired(event: Event): void {
    event.preventDefault();
    settle = (event as PasswordConfirmEvent).detail.settle;
    password.value = '';
    error.value = null;
    open.value = true;
    void nextTick(() => input.value?.focus());
}

function finish(confirmed: boolean): void {
    open.value = false;
    password.value = '';
    settle?.(confirmed);
    settle = null;
}

async function submit(): Promise<void> {
    if (busy.value || password.value === '') {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        await api('confirm-password', { method: 'POST', body: { password: password.value } });
        finish(true);
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            error.value = 'That password is incorrect.';
        } else if (e instanceof ApiError && e.status === 429) {
            error.value = 'Too many attempts. Wait a minute, then try again.';
        } else {
            error.value = describeError(e);
        }
        password.value = '';
        void nextTick(() => input.value?.focus());
    } finally {
        busy.value = false;
    }
}

apiEvents.addEventListener('password-confirm-required', onRequired);
onBeforeUnmount(() => apiEvents.removeEventListener('password-confirm-required', onRequired));
</script>

<template>
    <ModalDialog :open="open" title="Confirm your password" @close="finish(false)">
        <form class="mt-3 space-y-3" @submit.prevent="submit">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                This action needs your password again. Once confirmed, it runs automatically.
            </p>
            <div>
                <label for="dbx-confirm-password" class="block text-sm font-medium">Password</label>
                <input
                    id="dbx-confirm-password"
                    ref="input"
                    v-model="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="dbx-input mt-1 w-full"
                    :aria-invalid="error !== null"
                    aria-describedby="dbx-confirm-error"
                >
                <p id="dbx-confirm-error" role="alert" class="mt-1 min-h-5 text-sm text-red-700 dark:text-red-300">{{ error ?? '' }}</p>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="dbx-btn" @click="finish(false)">Cancel</button>
                <button type="submit" class="dbx-btn dbx-btn-primary" :disabled="busy || password === ''">{{ busy ? 'Checking…' : 'Confirm' }}</button>
            </div>
        </form>
    </ModalDialog>
</template>
