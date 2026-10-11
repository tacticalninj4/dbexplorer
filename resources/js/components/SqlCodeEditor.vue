<script setup lang="ts">
import { PostgreSQL, sql as sqlLanguage } from '@codemirror/lang-sql';
import { Compartment, EditorState } from '@codemirror/state';
import type { Extension } from '@codemirror/state';
import { EditorView } from '@codemirror/view';
import { basicSetup } from 'codemirror';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { catalogCompletion } from '../sql/completion';
import { editorTheme } from '../sql/editorTheme';

/**
 * A compact CodeMirror SQL field for forms: the SQL editor's PostgreSQL
 * language, catalog completion and theme (sql/editorTheme.ts), in a shadow
 * root for the strict CSP. The full workspace (tabs, run keys, error marks)
 * stays in SqlEditorView.
 *
 * Props:   label      connection label, for catalog completion (required)
 *          ariaLabel  accessible name of the text area (default "SQL")
 *          readonly   read-only document; read once, at mount
 *          invalid    red border and aria-invalid (reactive)
 * Model:   v-model    the SQL text; outside changes replace the document
 * Expose:  focus()
 * Size:    h-48, user-resizable; override the height with a class.
 */
const props = withDefaults(defineProps<{ label: string; ariaLabel?: string; readonly?: boolean; invalid?: boolean }>(), {
    ariaLabel: 'SQL',
    readonly: false,
    invalid: false,
});
const model = defineModel<string>({ required: true });

const host = ref<HTMLElement | null>(null);
const view = shallowRef<EditorView | null>(null);
const theme = new Compartment();
const attributes = new Compartment();
const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');

function contentAttributes(): Extension {
    return EditorView.contentAttributes.of({ 'aria-label': props.ariaLabel, ...(props.invalid ? { 'aria-invalid': 'true' } : {}) });
}

function onSchemeChange(): void {
    view.value?.dispatch({ effects: theme.reconfigure(editorTheme(darkQuery.matches)) });
}

onMounted(() => {
    const extensions: Extension[] = [
        basicSetup,
        sqlLanguage({ dialect: PostgreSQL, upperCaseKeywords: true }),
        PostgreSQL.language.data.of({ autocomplete: catalogCompletion(props.label) }),
        theme.of(editorTheme(darkQuery.matches)),
        EditorState.readOnly.of(props.readonly),
        EditorView.editable.of(!props.readonly),
        attributes.of(contentAttributes()),
        EditorView.updateListener.of((update) => {
            if (update.docChanged) {
                model.value = update.state.doc.toString();
            }
        }),
    ];
    const shadow = (host.value as HTMLElement).attachShadow({ mode: 'open' });
    view.value = new EditorView({ parent: shadow, root: shadow, state: EditorState.create({ doc: model.value, extensions }) });
    darkQuery.addEventListener('change', onSchemeChange);
});

// Outside changes (a reset, a prefill) replace the document.
watch(model, (value) => {
    const editor = view.value;
    if (editor && editor.state.doc.toString() !== value) {
        editor.dispatch({ changes: { from: 0, to: editor.state.doc.length, insert: value } });
    }
});

watch(
    () => [props.invalid, props.ariaLabel],
    () => view.value?.dispatch({ effects: attributes.reconfigure(contentAttributes()) }),
);

onBeforeUnmount(() => {
    darkQuery.removeEventListener('change', onSchemeChange);
    view.value?.destroy();
});

defineExpose({ focus: () => view.value?.focus() });
</script>

<template>
    <div ref="host" class="h-48 min-h-24 resize-y overflow-hidden rounded-md border bg-panel focus-within:outline-2 focus-within:-outline-offset-1 focus-within:outline-focus" :class="invalid ? 'border-danger-fg' : 'border-control'" />
</template>
