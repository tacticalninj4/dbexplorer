<script lang="ts">
/**
 * The icon set: authored on a 16px grid, 1.5 stroke, round caps, currentColor.
 * Shapes are data, rendered as SVG elements (never innerHTML). A shape is a
 * path `d`, or `c:cx cy r` (circle), `r:x y w h rx` (rect), `e:cx cy rx ry`.
 */
const ICONS = {
    table: ['r:2.25 2.75 11.5 10.5 1.5', 'M2.25 6.5h11.5', 'M6.25 6.5v6.75'],
    partition: ['r:2.25 2.75 11.5 10.5 1.5', 'M2.25 6.5h11.5', 'M2.25 9.75h11.5'],
    view: ['M1.75 8S4 3.75 8 3.75 14.25 8 14.25 8 12 12.25 8 12.25 1.75 8 1.75 8Z', 'c:8 8 1.75'],
    matview: ['M8 2.25 13.75 5 8 7.75 2.25 5Z', 'M2.25 8 8 10.75 13.75 8', 'M2.25 11 8 13.75 13.75 11'],
    sequence: ['M6.25 2.5 5 13.5', 'M11 2.5 9.75 13.5', 'M2.75 6h11', 'M2.25 10h11'],
    function: ['M11 2.75c-1.75-.5-3.1.2-3.4 2.1L6.4 11.15c-.3 1.9-1.65 2.6-3.4 2.1', 'M4.75 6.5h5.75'],
    type: ['M5.5 2.75c-1.25 0-2 .5-2 1.75v1.75c0 .75-.5 1.25-1.25 1.75.75.5 1.25 1 1.25 1.75v1.75c0 1.25.75 1.75 2 1.75', 'M10.5 2.75c1.25 0 2 .5 2 1.75v1.75c0 .75.5 1.25 1.25 1.75-.75.5-1.25 1-1.25 1.75v1.75c0 1.25-.75 1.75-2 1.75'],
    extension: ['M2.5 5 8 2.25 13.5 5v6L8 13.75 2.5 11Z', 'M2.5 5 8 7.75 13.5 5', 'M8 7.75v6'],
    schema: ['M2 4.5C2 3.67 2.67 3 3.5 3h2.88l1.5 1.75h4.62c.83 0 1.5.67 1.5 1.5v5.25c0 .83-.67 1.5-1.5 1.5h-9c-.83 0-1.5-.67-1.5-1.5Z'],
    database: ['e:8 4 5.25 1.75', 'M2.75 4v8c0 .97 2.35 1.75 5.25 1.75s5.25-.78 5.25-1.75V4', 'M2.75 8c0 .97 2.35 1.75 5.25 1.75S13.25 8.97 13.25 8'],
    terminal: ['r:1.75 2.75 12.5 10.5 1.5', 'M4.75 6.25 6.75 8l-2 1.75', 'M8.5 10h2.75'],
    history: ['M2.75 8a5.25 5.25 0 1 0 1.54-3.71', 'M2.5 2.5v2.75h2.75', 'M8 5.25V8l1.75 1.25'],
    activity: ['M1.75 8h2.5L6 3.5l4 9 1.75-4.5h2.5'],
    'chevron-right': ['M6 3.5 10.5 8 6 12.5'],
    'chevron-left': ['M10 3.5 5.5 8l4.5 4.5'],
    'chevron-down': ['M3.5 6 8 10.5 12.5 6'],
    search: ['c:7 7 4.25', 'M10.25 10.25 13.5 13.5'],
    x: ['M4 4l8 8', 'M12 4l-8 8'],
    plus: ['M8 3v10', 'M3 8h10'],
    play: ['M5 3.25v9.5L12.75 8Z'],
    lock: ['r:3.25 7 9.5 6.5 1.25', 'M5.25 7V5a2.75 2.75 0 0 1 5.5 0v2'],
    unlock: ['r:3.25 7 9.5 6.5 1.25', 'M5.25 7V5a2.75 2.75 0 0 1 5.35-.9'],
    key: ['c:5.25 10.75 2.5', 'M7.1 8.9 13 3', 'M10.75 5.25l1.75 1.75'],
    filter: ['M2.25 3.25h11.5L9.25 8.75v4l-2.5-1.25v-2.75Z'],
    columns: ['r:2.25 2.75 11.5 10.5 1.5', 'M6.1 2.75v10.5', 'M9.9 2.75v10.5'],
    copy: ['r:5.5 5.5 8 8 1.5', 'M10.5 5.5V3.75c0-.69-.56-1.25-1.25-1.25h-5.5c-.69 0-1.25.56-1.25 1.25v5.5c0 .69.56 1.25 1.25 1.25H5.5'],
    alert: ['M7.13 2.75a1 1 0 0 1 1.74 0l5.2 9.25a1 1 0 0 1-.87 1.5H2.8a1 1 0 0 1-.87-1.5Z', 'M8 6.25v3', 'M8 11.5v.01'],
    'alert-circle': ['c:8 8 5.75', 'M8 5v3.5', 'M8 10.9v.01'],
    info: ['c:8 8 5.75', 'M8 7.25v3.75', 'M8 5.1v.01'],
    check: ['M3.25 8.5 6.5 11.75 12.75 4.75'],
    'check-circle': ['c:8 8 5.75', 'M5.5 8.25 7.25 10l3.25-3.75'],
    'x-circle': ['c:8 8 5.75', 'M6 6l4 4', 'M10 6l-4 4'],
    eye: ['M1.75 8S4 3.75 8 3.75 14.25 8 14.25 8 12 12.25 8 12.25 1.75 8 1.75 8Z', 'c:8 8 1.75'],
    pencil: ['M10.75 2.75 13.25 5.25 5.5 13H3v-2.5Z', 'M9.25 4.25l2.5 2.5'],
    shield: ['M8 1.75 13.25 3.75v4c0 3.25-2.25 5.5-5.25 6.5-3-1-5.25-3.25-5.25-6.5v-4Z'],
    trash: ['M2.75 4.25h10.5', 'M6.25 4.25V3c0-.41.34-.75.75-.75h2c.41 0 .75.34.75.75v1.25', 'M4 4.25l.6 8.4c.05.62.57 1.1 1.2 1.1h4.4c.63 0 1.15-.48 1.2-1.1l.6-8.4'],
    refresh: ['M13.25 8a5.25 5.25 0 1 1-1.54-3.71', 'M13.5 2.5v2.75h-2.75'],
    'arrow-up': ['M8 13V3', 'M4 7l4-4 4 4'],
    'arrow-down': ['M8 3v10', 'M4 9l4 4 4-4'],
    'arrow-right': ['M3 8h10', 'M9 4l4 4-4 4'],
    download: ['M8 2.5v8', 'M4.5 7 8 10.5 11.5 7', 'M2.75 13.25h10.5'],
    stop: ['r:4 4 8 8 1.5'],
    sliders: ['M2.5 4.5h6', 'M11.5 4.5h2', 'M2.5 11.5h2', 'M7.5 11.5h6', 'c:10 4.5 1.5', 'c:6 11.5 1.5'],
    user: ['c:8 5.5 2.75', 'M2.75 13.5c.6-2.3 2.75-3.75 5.25-3.75s4.65 1.45 5.25 3.75'],
    plan: ['r:2.25 2.25 4.5 3.25 1', 'r:9.25 6.5 4.5 3.25 1', 'r:9.25 10.5 4.5 3.25 1', 'M4.5 5.5v6.6h4.75', 'M4.5 8.1h4.75'],
    message: ['M2.75 3.25h10.5v7.5H7l-3 2.5v-2.5H2.75Z'],
    inbox: ['M2.25 9.25 4 3.25h8l1.75 6v3.5H2.25Z', 'M2.25 9.25h3.5l.75 1.5h3l.75-1.5h3.5'],
    ban: ['c:8 8 5.75', 'M3.95 3.95l8.1 8.1'],
    clock: ['c:8 8 5.75', 'M8 5v3l2 1.25'],
    // batch 4A
    upload: ['M8 10.5v-8', 'M4.5 6 8 2.5 11.5 6', 'M2.75 13.25h10.5'],
    file: ['M9.25 1.75H4.5c-.69 0-1.25.56-1.25 1.25v10c0 .69.56 1.25 1.25 1.25h7c.69 0 1.25-.56 1.25-1.25V5.25Z', 'M9.25 1.75v3.5h3.5', 'M5.5 8.5h5', 'M5.5 11h3.5'],
    // end batch 4A
} as const satisfies Record<string, readonly string[]>;

export type IconName = keyof typeof ICONS;

interface Shape {
    kind: 'path' | 'circle' | 'rect' | 'ellipse';
    n: number[];
    d: string;
}

const KINDS = { c: 'circle', r: 'rect', e: 'ellipse' } as const;

function parse(spec: string): Shape {
    const kind = KINDS[spec.charAt(0) as keyof typeof KINDS];

    if (kind && spec.charAt(1) === ':') {
        return { kind, n: spec.slice(2).split(' ').map(Number), d: '' };
    }

    return { kind: 'path', n: [], d: spec };
}

const SHAPES = Object.fromEntries(Object.entries(ICONS).map(([name, specs]) => [name, specs.map(parse)])) as Record<IconName, Shape[]>;
</script>

<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ name: IconName; size?: number }>(), { size: 16 });
const shapes = computed(() => SHAPES[props.name]);
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 16 16"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        class="shrink-0"
    >
        <template v-for="(shape, index) in shapes" :key="index">
            <circle v-if="shape.kind === 'circle'" :cx="shape.n[0]" :cy="shape.n[1]" :r="shape.n[2]" />
            <rect v-else-if="shape.kind === 'rect'" :x="shape.n[0]" :y="shape.n[1]" :width="shape.n[2]" :height="shape.n[3]" :rx="shape.n[4]" />
            <ellipse v-else-if="shape.kind === 'ellipse'" :cx="shape.n[0]" :cy="shape.n[1]" :rx="shape.n[2]" :ry="shape.n[3]" />
            <path v-else :d="shape.d" />
        </template>
    </svg>
</template>
