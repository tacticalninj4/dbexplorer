<script setup lang="ts">
import { computed } from 'vue';
import { isActive, RUN_STATUS } from '../composables/runs';
import type { BackgroundRun } from '../composables/runs';
import BaseBadge from './BaseBadge.vue';

/** A run's status: icon, word and tone, plus "cancelling" while a cancel is pending. */
const props = defineProps<{ run: Pick<BackgroundRun, 'status' | 'cancel_requested'> }>();

const status = computed(() => RUN_STATUS[props.run.status]);
</script>

<template>
    <BaseBadge :tone="status.tone" :icon="status.icon">{{ status.text }}<template v-if="run.cancel_requested && isActive(run)"> · cancelling</template></BaseBadge>
</template>
