import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import type { Extension } from '@codemirror/state';
import { EditorView } from '@codemirror/view';
import { tags } from '@lezer/highlight';

// The editor lives in a shadow root: CodeMirror injects its styles, and the
// strict CSP (style-src 'self', S15) refuses <style> elements in the document,
// but allows constructable stylesheets, which CodeMirror uses in a shadow root.
// So every editor style, token colours included, is a CodeMirror theme here,
// shared by the SQL editor and every other CodeMirror instance.

function highlight(dark: boolean): Extension {
    const c = dark
        ? { keyword: '#c4b5fd', string: '#6ee7b7', number: '#7dd3fc', comment: '#a1a1aa', type: '#fcd34d', operator: '#d4d4d8', special: '#fda4af' }
        : { keyword: '#6d28d9', string: '#047857', number: '#0369a1', comment: '#71717a', type: '#b45309', operator: '#52525b', special: '#be123c' };

    return syntaxHighlighting(
        HighlightStyle.define(
            [
                { tag: tags.keyword, color: c.keyword, fontWeight: '500' },
                { tag: [tags.string, tags.special(tags.string)], color: c.string },
                { tag: [tags.number, tags.bool, tags.null, tags.atom], color: c.number },
                { tag: [tags.lineComment, tags.blockComment], color: c.comment, fontStyle: 'italic' },
                { tag: [tags.typeName, tags.standard(tags.name)], color: c.type },
                { tag: [tags.operator, tags.punctuation], color: c.operator },
                { tag: [tags.special(tags.name), tags.propertyName, tags.labelName], color: c.special },
            ],
            { themeType: dark ? 'dark' : 'light' },
        ),
    );
}

/** Mirrors the app.css tokens (panel, sunken, line, fg-subtle, accent). */
export function editorTheme(dark: boolean): Extension {
    const t = dark
        ? { bg: '#141417', fg: '#ededf0', gutter: '#141417', gutterFg: '#8d8d98', line: '#2a2a30', active: '#1a1a1e', activeGutter: '#222227', selection: '#0b3a55', tooltip: '#1a1a1e', pick: '#0ea5e9', onPick: '#03131d', error: '#5f1f1b' }
        : { bg: '#ffffff', fg: '#18181b', gutter: '#ffffff', gutterFg: '#686871', line: '#e3e3e7', active: '#f7f7f8', activeGutter: '#f3f3f5', selection: '#cfe6f5', tooltip: '#ffffff', pick: '#0369a1', onPick: '#ffffff', error: '#fee4e2' };

    return [highlight(dark), EditorView.theme(
        {
            '&': { height: '100%', fontSize: '13px', backgroundColor: t.bg, color: t.fg },
            '&.cm-focused': { outline: 'none' },
            '.cm-scroller': { fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace', lineHeight: '1.6' },
            '.cm-content': { padding: '8px 0' },
            '.cm-dbx-error': {
                textDecoration: 'underline wavy #d92d20',
                textUnderlineOffset: '3px',
                backgroundColor: t.error,
            },
            '.cm-gutters': { backgroundColor: t.gutter, color: t.gutterFg, borderRight: `1px solid ${t.line}` },
            '.cm-lineNumbers .cm-gutterElement': { padding: '0 8px 0 12px' },
            '.cm-activeLine': { backgroundColor: t.active },
            '.cm-activeLineGutter': { backgroundColor: t.activeGutter, color: t.fg },
            '.cm-cursor, .cm-dropCursor': { borderLeftColor: t.fg, borderLeftWidth: '2px' },
            '&.cm-focused .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': { backgroundColor: t.selection },
            '.cm-matchingBracket': { backgroundColor: t.selection, outline: 'none' },
            '.cm-tooltip': { backgroundColor: t.tooltip, color: t.fg, border: `1px solid ${t.line}`, borderRadius: '6px', overflow: 'hidden', boxShadow: '0 6px 16px -4px rgb(0 0 0 / 0.2)' },
            '.cm-tooltip-autocomplete > ul': { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace', fontSize: '12px' },
            '.cm-tooltip-autocomplete > ul > li': { padding: '2px 8px' },
            '.cm-tooltip-autocomplete > ul > li[aria-selected]': { backgroundColor: t.pick, color: t.onPick },
            '.cm-completionDetail': { opacity: '0.75', fontStyle: 'normal', marginLeft: '8px' },
            '.cm-panels': { backgroundColor: t.tooltip, color: t.fg, borderColor: t.line },
            '.cm-foldPlaceholder': { backgroundColor: t.active, border: `1px solid ${t.line}`, color: t.gutterFg },
        },
        { dark },
    )];
}
