<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { RouteLocationRaw } from 'vue-router';
import { api, describeError, segment } from '../api';
import * as catalog from '../composables/catalog';
import { openCreate } from '../composables/ddl';
import type { CreateKind } from '../composables/ddl';
import { findConnection } from '../composables/session';
import type { DbObject, Extension, ObjectType, Schema } from '../types';
import BaseIcon from './BaseIcon.vue';
import type { IconName } from './BaseIcon.vue';

/**
 * Schemas, then object groups by type, loaded per schema on first expand.
 * Keyboard: a WAI-ARIA tree with one tab stop; arrows move and expand,
 * Home/End jump, Enter opens. Admins get a create menu on schema nodes
 * (right-click, the Menu key or Shift+F10) and behind the "New" button.
 */
const props = defineProps<{ label: string }>();

const route = useRoute();
const router = useRouter();

interface Group {
    key: string;
    title: string;
    types: ObjectType[];
}

const GROUPS: Group[] = [
    { key: 'tables', title: 'Tables', types: ['table', 'foreign_table'] },
    { key: 'partitioned', title: 'Partitioned tables', types: ['partitioned_table'] },
    { key: 'views', title: 'Views', types: ['view'] },
    { key: 'matviews', title: 'Materialized views', types: ['materialized_view'] },
    { key: 'sequences', title: 'Sequences', types: ['sequence'] },
    { key: 'functions', title: 'Functions', types: ['function', 'procedure'] },
    { key: 'types', title: 'Types', types: ['enum', 'domain', 'composite_type'] },
];

interface Node {
    key: string;
    level: number;
    text: string;
    hint?: string;
    kind: 'schema' | 'group' | 'object' | 'extension' | 'message';
    icon?: IconName;
    expandable: boolean;
    expanded: boolean;
    selected: boolean;
    to?: RouteLocationRaw;
    /** For expandable nodes: what to toggle. */
    toggle?: string;
    /** Parent node key, for ArrowLeft. */
    parent?: string;
}

const schemas = ref<Schema[]>([]);
const extensions = ref<Extension[]>([]);
const objects = ref<Record<string, DbObject[] | 'loading' | { error: string }>>({});
const expanded = ref(new Set<string>());
const error = ref<string | null>(null);
const focusedKey = ref<string | null>(null);
const treeEl = ref<HTMLElement | null>(null);

const query = ref('');
const results = ref<DbObject[] | null>(null);
const searching = ref(false);
const searchError = ref<string | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let searchSequence = 0;

function objectRoute(object: DbObject): RouteLocationRaw {
    return {
        name: 'object',
        params: { label: props.label, schema: object.schema, name: object.signature ?? object.name },
        query: object.signature !== null ? { fn: '1' } : {},
    };
}

function isCurrent(object: DbObject): boolean {
    return route.name === 'object'
        && route.params.schema === object.schema
        && route.params.name === (object.signature ?? object.name)
        && (route.query.fn === '1') === (object.signature !== null);
}

const TYPE_ICONS: Record<ObjectType, IconName> = {
    table: 'table',
    partitioned_table: 'partition',
    foreign_table: 'table',
    view: 'view',
    materialized_view: 'matview',
    sequence: 'sequence',
    function: 'function',
    procedure: 'function',
    enum: 'type',
    domain: 'type',
    composite_type: 'type',
};

function typeIcon(object: DbObject): IconName {
    return object.parent ? 'partition' : (TYPE_ICONS[object.type] ?? 'table');
}

function typeHint(object: DbObject): string | undefined {
    if (object.parent) {
        return 'partition';
    }

    return { foreign_table: 'foreign', procedure: 'procedure', enum: 'enum', domain: 'domain', composite_type: 'composite' }[object.type as string];
}

const nodes = computed<Node[]>(() => {
    const list: Node[] = [];

    for (const schema of schemas.value) {
        const schemaKey = `s:${schema.name}`;
        const open = expanded.value.has(schemaKey);
        list.push({ key: schemaKey, level: 1, text: schema.name, kind: 'schema', icon: 'schema', expandable: true, expanded: open, selected: false, toggle: schemaKey });

        if (!open) {
            continue;
        }

        const loaded = objects.value[schema.name];

        if (loaded === undefined || loaded === 'loading') {
            list.push({ key: `${schemaKey}:loading`, level: 2, text: 'Loading…', kind: 'message', expandable: false, expanded: false, selected: false, parent: schemaKey });
            continue;
        }

        if (!Array.isArray(loaded)) {
            list.push({ key: `${schemaKey}:error`, level: 2, text: loaded.error, kind: 'message', expandable: false, expanded: false, selected: false, parent: schemaKey });
            continue;
        }

        const groups: { key: string; title: string; items: Node[] }[] = GROUPS.map((group) => ({
            key: group.key,
            title: group.title,
            items: loaded
                .filter((object) => group.types.includes(object.type))
                .map((object) => ({
                    key: `o:${schema.name}:${object.type}:${object.signature ?? object.name}`,
                    level: 3,
                    text: object.signature ?? object.name,
                    hint: typeHint(object),
                    kind: 'object' as const,
                    icon: typeIcon(object),
                    expandable: false,
                    expanded: false,
                    selected: isCurrent(object),
                    to: objectRoute(object),
                })),
        }));

        groups.push({
            key: 'extensions',
            title: 'Extensions',
            items: extensions.value
                .filter((extension) => extension.schema === schema.name)
                .map((extension) => ({
                    key: `e:${extension.name}`,
                    level: 3,
                    text: extension.name,
                    hint: extension.version,
                    kind: 'extension' as const,
                    icon: 'extension' as const,
                    expandable: false,
                    expanded: false,
                    selected: false,
                })),
        });

        const visibleGroups = groups.filter((group) => group.items.length > 0);

        if (visibleGroups.length === 0) {
            list.push({ key: `${schemaKey}:empty`, level: 2, text: 'No objects you can access', kind: 'message', expandable: false, expanded: false, selected: false, parent: schemaKey });
        }

        for (const group of visibleGroups) {
            const groupKey = `g:${schema.name}:${group.key}`;
            const groupOpen = expanded.value.has(groupKey);
            list.push({ key: groupKey, level: 2, text: group.title, hint: String(group.items.length), kind: 'group', expandable: true, expanded: groupOpen, selected: false, toggle: groupKey, parent: schemaKey });

            if (groupOpen) {
                list.push(...group.items.map((item) => ({ ...item, parent: groupKey })));
            }
        }
    }

    return list;
});

/** The single tab stop: the focused node, else the selected one, else the first. */
const tabStop = computed(() => {
    const keys = nodes.value.filter((node) => node.kind !== 'message').map((node) => node.key);

    if (focusedKey.value !== null && keys.includes(focusedKey.value)) {
        return focusedKey.value;
    }

    return nodes.value.find((node) => node.selected)?.key ?? keys[0] ?? null;
});

async function loadSchema(schema: string): Promise<void> {
    if (Array.isArray(objects.value[schema]) || objects.value[schema] === 'loading') {
        return;
    }

    objects.value[schema] = 'loading';

    try {
        objects.value[schema] = await catalog.objects(props.label, schema);
    } catch (e) {
        objects.value[schema] = { error: describeError(e) };
    }
}

function expand(key: string, open = true): void {
    const next = new Set(expanded.value);

    if (open) {
        next.add(key);
    } else {
        next.delete(key);
    }

    if (open && key.startsWith('s:')) {
        // Tables are what people open most: show them right away.
        next.add(`g:${key.slice(2)}:tables`);
        void loadSchema(key.slice(2));
    }

    expanded.value = next;
}

function activate(node: Node): void {
    focusedKey.value = node.key;

    if (node.toggle) {
        expand(node.toggle, !node.expanded);
    } else if (node.to) {
        void router.push(node.to);
    }
}

function focusNode(key: string | undefined): void {
    if (key === undefined) {
        return;
    }

    focusedKey.value = key;
    void nextTick(() => {
        treeEl.value?.querySelector<HTMLElement>(`[data-key="${CSS.escape(key)}"]`)?.focus();
    });
}

function onKeydown(event: KeyboardEvent, node: Node): void {
    if (isAdmin.value && node.kind === 'schema' && (event.key === 'ContextMenu' || (event.key === 'F10' && event.shiftKey))) {
        event.preventDefault();
        const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
        openMenu(node.text, rect.bottom, rect.left + 24, event.currentTarget as HTMLElement);
        return;
    }

    const list = nodes.value.filter((item) => item.kind !== 'message');
    const index = list.findIndex((item) => item.key === node.key);

    switch (event.key) {
        case 'ArrowDown':
            focusNode(list[index + 1]?.key);
            break;
        case 'ArrowUp':
            focusNode(list[index - 1]?.key);
            break;
        case 'Home':
            focusNode(list[0]?.key);
            break;
        case 'End':
            focusNode(list[list.length - 1]?.key);
            break;
        case 'ArrowRight':
            if (node.expandable && !node.expanded && node.toggle) {
                expand(node.toggle);
            } else if (node.expandable && list[index + 1]?.parent === node.key) {
                focusNode(list[index + 1]?.key);
            }
            break;
        case 'ArrowLeft':
            if (node.expandable && node.expanded && node.toggle) {
                expand(node.toggle, false);
            } else {
                focusNode(node.parent);
            }
            break;
        case 'Enter':
        case ' ':
            activate(node);
            break;
        default:
            return;
    }

    event.preventDefault();
}

function onSearch(): void {
    clearTimeout(searchTimer);
    const term = query.value.trim();

    if (term === '') {
        results.value = null;
        searchError.value = null;
        searching.value = false;
        return;
    }

    searching.value = true;
    searchTimer = setTimeout(async () => {
        const id = ++searchSequence;

        try {
            const found = await api<{ data: DbObject[] }>(`${segment(props.label)}/search`, { query: { q: term.slice(0, 100) } });
            if (id === searchSequence) {
                results.value = found.data;
                searchError.value = null;
            }
        } catch (e) {
            if (id === searchSequence) {
                searchError.value = describeError(e);
            }
        } finally {
            if (id === searchSequence) {
                searching.value = false;
            }
        }
    }, 250);
}

async function load(): Promise<void> {
    error.value = null;
    schemas.value = [];
    extensions.value = [];
    objects.value = {};
    expanded.value = new Set();

    try {
        const [list, extensionList] = await Promise.all([catalog.schemas(props.label), catalog.extensions(props.label).catch(() => [])]);
        schemas.value = list;
        extensions.value = extensionList;
        revealCurrent();

        // A single schema (often just public) is opened for you.
        if (list.length === 1 && list[0]) {
            expand(`s:${list[0].name}`);
        }
    } catch (e) {
        error.value = describeError(e, 'Could not load schemas.');
    }
}

/** Expand the schema and group of the object the route shows. */
function revealCurrent(): void {
    const schema = route.params.schema;

    if (route.name !== 'object' || typeof schema !== 'string' || !schemas.value.some((s) => s.name === schema)) {
        return;
    }

    const next = new Set(expanded.value);
    next.add(`s:${schema}`);
    expanded.value = next;

    void loadSchema(schema).then(() => {
        const loaded = objects.value[schema];
        const current = Array.isArray(loaded) ? loaded.find(isCurrent) : undefined;
        const group = current ? GROUPS.find((g) => g.types.includes(current.type)) : undefined;

        if (group) {
            const opened = new Set(expanded.value);
            opened.add(`g:${schema}:${group.key}`);
            expanded.value = opened;
        }
    });
}

watch(() => props.label, load, { immediate: true });
watch(() => route.fullPath, revealCurrent);

// A schema change elsewhere (DDL form) dropped cached objects: reload what's open.
watch(catalog.catalogVersion, () => {
    const reloads = Object.keys(objects.value).map((schema) => {
        delete objects.value[schema];

        return loadSchema(schema);
    });

    // A create form may have navigated to the new object meanwhile: show it.
    void Promise.all(reloads).then(revealCurrent);
});

// Create menu (admins): New table / view / materialized view in a schema.
const isAdmin = computed(() => findConnection(props.label)?.role === 'admin');
const CREATE_ITEMS: { kind: CreateKind; title: string; icon: IconName }[] = [
    { kind: 'table', title: 'New table…', icon: 'table' },
    { kind: 'view', title: 'New view…', icon: 'view' },
    { kind: 'materialized_view', title: 'New materialized view…', icon: 'matview' },
];
const menu = ref<{ schema: string; top: number; left: number; trigger: HTMLElement | null } | null>(null);
const menuEl = ref<HTMLElement | null>(null);
const newButton = ref<HTMLElement | null>(null);

function openMenu(schema: string, top: number, left: number, trigger: HTMLElement | null): void {
    menu.value = { schema, top: Math.min(top, window.innerHeight - 120), left: Math.min(left, window.innerWidth - 240), trigger };
    document.addEventListener('pointerdown', onOutside, true);
    void nextTick(() => menuEl.value?.querySelector<HTMLElement>('[role="menuitem"]')?.focus());
}

function closeMenu(restoreFocus = true): void {
    const trigger = menu.value?.trigger;
    menu.value = null;
    document.removeEventListener('pointerdown', onOutside, true);

    if (restoreFocus) {
        trigger?.focus();
    }
}

function onOutside(event: Event): void {
    if (!(event.target instanceof globalThis.Node && menuEl.value?.contains(event.target)) && event.target !== menu.value?.trigger) {
        closeMenu(false);
    }
}

/** The schema "New" creates in: the open object's, else public, else the first. */
function defaultSchema(): string {
    const current = route.params.schema;

    if (route.name === 'object' && typeof current === 'string') {
        return current;
    }

    return schemas.value.some((schema) => schema.name === 'public') ? 'public' : (schemas.value[0]?.name ?? 'public');
}

function toggleNewMenu(): void {
    if (menu.value) {
        closeMenu();
        return;
    }

    const rect = newButton.value?.getBoundingClientRect();
    openMenu(defaultSchema(), (rect?.bottom ?? 0) + 4, rect?.left ?? 0, newButton.value);
}

function onNodeContextMenu(event: MouseEvent, node: Node): void {
    if (!isAdmin.value || node.kind !== 'schema') {
        return;
    }

    event.preventDefault();
    focusedKey.value = node.key;
    openMenu(node.text, event.clientY, event.clientX, event.currentTarget as HTMLElement);
}

function onMenuKeydown(event: KeyboardEvent): void {
    const items = [...(menuEl.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])];
    const index = items.indexOf(document.activeElement as HTMLElement);
    const moves: Record<string, number> = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: items.length - 1 };

    if (event.key === 'Escape') {
        event.preventDefault();
        closeMenu();
    } else if (event.key === 'Tab') {
        closeMenu(false);
    } else if (moves[event.key] !== undefined) {
        event.preventDefault();
        items[((moves[event.key] as number) + items.length) % items.length]?.focus();
    }
}

function create(kind: CreateKind): void {
    const schema = menu.value?.schema ?? defaultSchema();
    closeMenu(false);
    openCreate(kind, schema);
}

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    document.removeEventListener('pointerdown', onOutside, true);
});

const levelPadding = ['pl-1.5', 'pl-1.5', 'pl-4', 'pl-[1.875rem]'];
</script>

<template>
    <nav class="flex min-h-0 flex-1 flex-col" aria-label="Database objects">
        <div class="flex items-center gap-1.5 px-2 pt-2.5 pb-2">
            <label for="dbx-object-search" class="sr-only">Search objects</label>
            <div class="relative min-w-0 flex-1">
                <BaseIcon name="search" :size="14" class="pointer-events-none absolute top-1/2 left-2 -translate-y-1/2 text-fg-subtle" />
                <input
                    id="dbx-object-search"
                    v-model="query"
                    type="search"
                    placeholder="Search objects"
                    autocomplete="off"
                    maxlength="100"
                    class="dbx-input w-full pl-7"
                    @input="onSearch"
                    @keydown.esc="query = ''; onSearch()"
                >
                <span v-if="searching" class="dbx-spinner absolute top-1/2 right-2 -translate-y-1/2" aria-hidden="true" />
            </div>
            <button
                v-if="isAdmin"
                ref="newButton"
                type="button"
                class="dbx-btn dbx-btn-icon"
                aria-label="New table or view"
                title="New table or view"
                aria-haspopup="menu"
                :aria-expanded="menu !== null && menu.trigger === newButton"
                @click="toggleNewMenu"
            >
                <BaseIcon name="plus" />
            </button>
        </div>

        <Transition name="dbx-pop">
            <div
                v-if="menu"
                ref="menuEl"
                role="menu"
                :aria-label="`Create in schema ${menu.schema}`"
                class="dbx-menu fixed mt-0 w-56"
                :style="{ top: `${menu.top}px`, left: `${menu.left}px` }"
                @keydown="onMenuKeydown"
            >
                <p class="truncate px-2 pt-1 pb-1.5 text-xs text-fg-subtle" aria-hidden="true">In schema <span class="font-medium text-fg-muted">{{ menu.schema }}</span></p>
                <button v-for="item in CREATE_ITEMS" :key="item.kind" type="button" role="menuitem" tabindex="-1" class="dbx-menu-item" @click="create(item.kind)">
                    <BaseIcon :name="item.icon" :size="14" class="text-fg-subtle" />{{ item.title }}
                </button>
            </div>
        </Transition>

        <div class="min-h-0 flex-1 overflow-auto pb-2 text-sm">
            <template v-if="query.trim() !== ''">
                <p v-if="searching && results === null" class="px-3 py-2 text-fg-muted" role="status">Searching…</p>
                <p v-else-if="searchError" role="alert" class="mx-2 dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" />{{ searchError }}</p>
                <p v-else-if="results && results.length === 0" class="px-3 py-2 text-fg-muted">No objects match “{{ query.trim() }}”.</p>
                <ul v-else-if="results" aria-label="Search results" class="px-1.5">
                    <li v-for="object in results" :key="`${object.schema}.${object.type}.${object.signature ?? object.name}`">
                        <router-link
                            :to="objectRoute(object)"
                            class="flex h-[26px] items-center gap-2 rounded px-1.5 transition-colors duration-100 outline-offset-[-2px] hover:bg-hover"
                            :class="isCurrent(object) ? 'bg-accent-soft text-accent-soft-fg' : ''"
                        >
                            <BaseIcon :name="typeIcon(object)" :size="14" class="text-fg-subtle" />
                            <span class="truncate">{{ object.signature ?? object.name }}</span>
                            <span class="ml-auto shrink-0 truncate text-xs text-fg-subtle">{{ object.schema }}</span>
                        </router-link>
                    </li>
                </ul>
            </template>

            <template v-else>
                <p v-if="error" role="alert" class="mx-2 dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" />{{ error }}</p>
                <div v-else-if="schemas.length === 0" class="space-y-2.5 px-3 py-2" role="status" aria-label="Loading schemas">
                    <span class="dbx-skeleton w-24" /><span class="dbx-skeleton w-32" /><span class="dbx-skeleton w-20" />
                </div>
                <ul v-else ref="treeEl" role="tree" aria-label="Schemas" class="px-1.5">
                    <li
                        v-for="node in nodes"
                        :key="node.key"
                        :data-key="node.key"
                        :role="node.kind === 'message' ? 'none' : 'treeitem'"
                        :aria-level="node.level"
                        :aria-expanded="node.expandable ? node.expanded : undefined"
                        :aria-selected="node.kind === 'object' ? node.selected : undefined"
                        :tabindex="node.kind === 'message' ? undefined : node.key === tabStop ? 0 : -1"
                        class="flex h-[26px] cursor-default items-center gap-1.5 rounded pr-1.5 outline-offset-[-2px] select-none"
                        :class="[
                            levelPadding[node.level],
                            node.kind === 'message' ? 'text-fg-subtle italic' : 'transition-colors duration-100 hover:bg-hover',
                            node.selected ? 'bg-accent-soft! text-accent-soft-fg' : '',
                        ]"
                        :aria-haspopup="isAdmin && node.kind === 'schema' ? 'menu' : undefined"
                        @click="node.kind !== 'message' && activate(node)"
                        @focus="node.kind !== 'message' && (focusedKey = node.key)"
                        @keydown="onKeydown($event, node)"
                        @contextmenu="onNodeContextMenu($event, node)"
                    >
                        <span class="grid w-3.5 shrink-0 place-items-center text-fg-subtle" aria-hidden="true">
                            <BaseIcon
                                v-if="node.expandable"
                                name="chevron-right"
                                :size="12"
                                class="transition-transform duration-150 ease-pop motion-reduce:transition-none"
                                :class="node.expanded ? 'rotate-90' : ''"
                            />
                        </span>
                        <BaseIcon v-if="node.icon" :name="node.icon" :size="14" :class="node.selected ? '' : node.kind === 'schema' ? 'text-fg-muted' : 'text-fg-subtle'" />
                        <span
                            class="truncate"
                            :class="node.kind === 'schema' ? 'font-medium' : node.kind === 'group' ? 'text-fg-muted' : ''"
                        >{{ node.text }}</span>
                        <!-- The partition icon already says it: keep the word for screen readers only. -->
                        <span v-if="node.hint === 'partition'" class="sr-only">(partition)</span>
                        <span v-else-if="node.hint" class="ml-auto shrink-0 pl-2 text-xs tabular-nums" :class="node.selected ? 'text-accent-soft-fg' : 'text-fg-subtle'">{{ node.hint }}</span>
                    </li>
                </ul>
            </template>
        </div>
    </nav>
</template>
