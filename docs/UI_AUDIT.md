# UI audit and design system

The SPA was audited screen by screen in light and dark mode, at 1280 px and 1024 px, against a Postgres-backed workbench (the `fixture` schema). Screenshots are in `~/Downloads/dbx-ui-polish/{before,after}/`. Findings are ranked by impact: what is wrong, what fixes it, and why it matters.

## 1. Findings

| # | Impact | Issue | Fix | Why |
|---|---|---|---|---|
| 1 | High | Primary buttons are white on `sky-600` (4.1:1), dark ones too. Tertiary text (`zinc-400`) is used for the schema prefix in object titles, row numbers, type hints, `(auto: …)` stats and NULL pills, at 2.5–2.9:1. | Token pairs that all pass AA (§3): `accent-solid` is `#0369a1` in light (5.9:1), and in dark it's `#0ea5e9` with near-black text (6.8:1). `fg-subtle` is at least 4.5:1 on every surface. | WCAG 1.4.3. These are the labels people scan most. |
| 2 | High | Destructive intent is inconsistent. "Save changes" and "Insert row" in the row editor are red (danger) like "Delete row". The DDL form's "Execute" reads the same for "Rename column" and "Drop table". The 409 confirm says "Run it" for every classification. | Danger styling only where data is lost. The confirm button names the action ("Run destructive statement", "Drop column"). A callout above it states the consequence (what is lost, that it can't be undone here, that a password is needed). Cancel gets focus by default. | Red that means "submit" teaches people to ignore red. |
| 3 | High | The Data tab toolbar wraps onto two lines at 1280 px, with row actions centred between pagination and Filters/Columns. Pagination competes with row actions for the same row. | A top toolbar holds row actions (left) and Filters/Columns (right). A bottom status bar holds pagination, page size, row count/estimate, exact count and the pagination mode. | The grid gets the vertical space, and every control has one home. TablePlus and Supabase use the same split. |
| 4 | High | The sidebar nav ("Overview · SQL editor · History · Runs") wraps "SQL editor" onto two lines. It has no icons, and batch 4 adds about 8 more destinations. | A vertical nav list with icons at the top of the sidebar, then the object tree under an "Objects" heading. | It scales to the F24 screens without a redesign. |
| 5 | Med | The object tree is text-only. Tables, views, functions and types look identical once groups are open, and the chevron is a `▸` glyph. | Inline SVG icons per object type (table, partition, view, materialized view, sequence, function, type, extension, schema). The SVG chevron rotates. Rows are 26 px, and counts are right-aligned. | It's scannable at a glance, and it follows the craft floor's "drawn icons, not glyphs" rule. |
| 6 | Med | Grid data is in the proportional UI font. UUIDs, numbers and timestamps don't line up, and empty strings render as nothing (indistinguishable from a blank cell). | Cells use `font-mono text-data` with tabular numbers. NULL is a dashed-outline pill. An empty string shows a muted `''` titled "Empty string". Masked cells show a lock, a "Masked" label for screen readers and dots. Expanded long text and JSON get a minimum width, so they don't wrap into a 90 px column. | NULL vs '' vs masked is the main question when debugging data. This shows it without relying on color. |
| 7 | Med | Columns of different fonts (sans name, mono type) are top-aligned in reference tables, so the baselines jump. Nullable reads "not null"/"yes" (mixed polarity). | `align-baseline` in `.dbx-table`. Nullable is shown as a `NOT NULL` badge or "nullable". | It's easier to read down a column. |
| 8 | Med | Loading is the text "Loading…" everywhere, and the first load of a grid shows an empty table. | Skeleton rows for tables, the grid and key/value lists. The spinner is only for in-place refreshes (the grid overlay, Run). | Layout doesn't jump, and loading reads as structure arriving. |
| 9 | Med | Empty and error states are bare sentences. "No rows." doesn't say whether filters caused it. The 404 page has no way out. | An `EmptyState` component (icon, title, hint, action), for example "No rows match these filters" with a Clear filters action. Errors use `dbx-callout-danger` with an icon. | Empty states should teach the next step. |
| 10 | Med | The SQL result bar shows "5 rows" for a `DELETE` that returns no result set (**F25e**). | A statement with no result set shows "N rows affected" only. | Rows *returned* and rows *affected* mean different things after a write. |
| 11 | Med | The "Queued as run #N" notice never shows, because the DDL dialog closes first (**F25a**). After a drop or rename, the tree still lists the old table, and the page stays on a 404 (**F25b**). | A success banner on the object page, which outlives the dialog and links to Runs. After a drop or rename the tree's schema cache is invalidated and reloaded, and the page moves to the connection overview (drop) or the new name (rename). | The user can see that a background job started, and that a drop worked. |
| 12 | Med | The role badge is an unlabelled grey pill, the same for viewer, editor and admin. | `RoleBadge`: icon + text + tone (viewer neutral/eye, editor teal/pencil, admin violet/shield), with an explanatory title. | People should always know what they're allowed to do. Without that, every write looks risky. |
| 13 | Low | Modal titles have no spacing from their content, and there's no close affordance besides Esc. | `ModalDialog` has a header row (title, optional description, close button) and a body with consistent padding. The dialog itself doesn't draw a focus ring (it showed a blue double border on open). | Polish, and it's predictable for mouse users. |
| 14 | Low | Columns popover: it only closes on Esc or its trigger. | Click-outside closes it, plus "Show all". | The expected popover behaviour. |
| 15 | Low | EXPLAIN tree: all nodes have the same weight, and the hot path is invisible. | A node header with the node type in bold and the relation in mono. The cost/rows/width metrics are quieter. With ANALYZE, a thin bar shows each node's share of total time, and a node at 30% or more of the total time is marked "slowest". | It answers "where does the time go?" at a glance. |
| 16 | Low | Hard-coded `zinc-*`/`sky-*` with `dark:` twins in every template: about 400 utility pairs. | Semantic tokens (`bg-panel`, `text-fg-muted`, `border-line`, …) that switch with `prefers-color-scheme`. | Batch 4 builds about 9 screens. One vocabulary keeps them consistent, and CSS shrinks. |

**Fixed from F25:** a (queued notice), b (stale tree and 404 after a drop or rename) and e (rows affected). **Deferred:** c ("overwrite with mine" needs a product decision on clobbering) and d (an API change, no PHP in this batch).

## 2. Design system

The tokens and component classes live in `resources/js/app.css`, with documentation at the top of the file. Summary for batch 4:

- **Surfaces:** `canvas` < `panel` < `sunken` / `hover`. The header, sidebar, toolbars, dialogs and popovers are `panel`. Content scrolls on `canvas`, and the grid header and code are `sunken`.
- **Text:** `fg`, `fg-muted` (secondary) and `fg-subtle` (tertiary). All three pass AA on every surface.
- **Accent:** `accent` (links), `accent-solid`/`on-accent` (primary buttons), `accent-soft`/`accent-soft-fg` (selection, the active nav item) and `focus` (the one global `:focus-visible` outline).
- **Tones:** `success`, `warning`, `danger`, `info`, `orange`, `violet` and `teal`, each with `-bg`, `-fg` and `-line`. `danger-solid` is for destructive buttons.
- **Meaning maps:**
  - Roles: viewer = neutral, editor = teal, admin = violet.
  - Classification: read = success, dml = warning, ddl = orange, destructive = danger.
  - Each one always comes with a text label, and roles also get an icon.
- **Type:**
  - UI: system UI at 13 px. Steps are `text-xs` 12, `text-sm` 13, `text-base` 14, `text-lg` 16 and `text-xl` 18.
  - Data and SQL: platform monospace, with grid cells at `text-data` 12.5 px and tabular numbers.
  - No web fonts: the CSP allows them via `dist/`, but system stacks render instantly and look native beside the host app.
- **Density:** 28 px controls (`h-7`), 24 px small controls, 26 px tree rows, 28 px grid rows, and 40 px toolbars.
- **Motion:** `ease-pop` with `duration-fast` (120 ms) and `duration-base` (180 ms).
  - What animates: press scale (0.97), popover scale-in from its trigger, dialog rise and fade, chevron rotation, and the inline reveal of the filter panel.
  - Never animated: keyboard navigation and anything that happens dozens of times a minute.
  - Reduced motion keeps the fades and drops the movement.
- **Classes:**
  - `dbx-btn`, with modifiers `-primary`, `-danger`, `-ghost`, `-danger-ghost`, `-sm`, `-icon` and `-pressed`.
  - Form controls: `dbx-input`, `dbx-select`, `dbx-label` and `dbx-check`.
  - Inline labels: `dbx-badge` and `dbx-kbd`.
  - Tabs: `dbx-tabs`/`dbx-tab` and `dbx-segmented`.
  - Bars: `dbx-toolbar`, `dbx-statusbar` and `dbx-page-header`.
  - Callouts: `dbx-callout` (with `-danger`, `-warning`, `-info` and `-success`) and `dbx-banner`.
  - Content: `dbx-kv`, `dbx-table`, `dbx-card`, `dbx-menu`/`dbx-menu-item` and `dbx-code`.
  - Loading: `dbx-skeleton` and `dbx-spinner`.
- **Components:**
  - `BaseIcon`: inline SVG with a 16 px grid, a 1.5 stroke and `currentColor`. Shapes are data rendered as SVG elements, never HTML strings.
  - `BaseBadge` (`tone` and `icon` props), `RoleBadge` and `ClassificationBadge`.
  - `EmptyState`: icon, title, hint and an actions slot.
  - `SkeletonRows`.
  - `ModalDialog`: title, description, a close button and a `footer` slot.
  - `CodeBlock`, `ResultBar` (the SQL result summary) and `DataGrid` (toolbar and footer slots, resizable columns, arrow-key rows).

## 3. Contrast (WCAG 2.x, computed)

| Pair | Light | Dark |
|---|---|---|
| `fg` on `panel` / `canvas` | 17.7 / 16.6 | 15.7 / 16.7 |
| `fg-muted` on `panel` / `sunken` / `hover` | 7.7 / 7.0 / 6.6 | 7.8 / 7.4 / 6.7 |
| `fg-subtle` on `panel` / `canvas` / `sunken` / `hover` | 5.5 / 5.2 / 5.0 / 4.7 | 5.6 / 6.0 / 5.3 / 4.8 |
| `accent` (link) on `panel` / `canvas` / `sunken` | 5.9 / 5.5 / 5.4 | 8.6 / 9.1 / 8.1 |
| `on-accent` on `accent-solid` (primary button) | 5.9 | 6.8 |
| `accent-soft-fg` on `accent-soft` (selection) | 8.4 | 11.6 |
| white on `danger-solid` (destructive button) | 4.8 | 4.8 |
| `success` / `warning` / `danger` fg on bg | 7.3 / 7.2 / 6.1 | 10.3 / 10.5 / 9.1 |
| `orange` / `violet` / `teal` fg on bg | 5.3 / 7.0 / 5.3 | 9.5 / 9.0 / 10.9 |
| `focus` ring on `panel` / `canvas` (non-text, ≥ 3) | 4.1 / 3.8 | 8.6 / 9.1 |
| `control` border on `panel` (non-text, ≥ 3) | 3.2 | 3.3 |

Every text pair passes AA (4.5:1), and the focus ring and control borders pass the 3:1 non-text minimum. The script that computed these is `/tmp/dbx-ui-tools/contrast.mjs`, a sRGB relative-luminance formula.

## 4. Verification

- `npm run lint`, `npm run typecheck` and `composer test` pass, and two `npm run build` runs produce byte-identical `dist/`.
- The headless and in-browser runs against the Postgres workbench showed no console errors and no CSP violations.
- Bundle size (gzip -9), before → after:
  - CSS: 7.9 → 9.1 KB.
  - Entry JS: 59.7 → 67.5 KB.
  - SQL editor chunk: 147.3 → 149.0 KB.
  - The increase is the icon set, the new components and the token fallbacks. No fonts or dependencies were added.
