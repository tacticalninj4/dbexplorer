<script setup lang="ts">
/** Placeholder lines while a table or list loads: structure first, then data. */
withDefaults(defineProps<{ rows?: number; columns?: number }>(), { rows: 6, columns: 4 });

// Fixed, varied widths so the placeholder doesn't look like a barcode.
const WIDTHS = ['w-3/4', 'w-1/2', 'w-5/6', 'w-2/3', 'w-2/5', 'w-3/5'];
</script>

<template>
    <div class="space-y-0" role="status" aria-label="Loading">
        <div v-for="row in rows" :key="row" class="flex gap-6 border-b border-line-subtle px-3 py-2.5">
            <span v-for="column in columns" :key="column" class="flex-1">
                <span class="dbx-skeleton" :class="WIDTHS[(row * 3 + column) % WIDTHS.length]" />
            </span>
        </div>
    </div>
</template>
