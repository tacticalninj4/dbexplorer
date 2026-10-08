import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

// Built assets are committed to dist/ and served from the vendor directory by
// the package's AssetController under a configurable URL prefix (decision D2).
//
// - `base: './'` keeps every URL that Vite writes into the bundle (lazy chunks,
//   their CSS, fonts/images) relative to the file that references it, so the
//   output works under any prefix without a rebuild.
// - The manifest at dist/.vite/manifest.json is the allowlist the
//   AssetController serves from, and what the Blade shell reads to find the
//   entry script and stylesheet (key: "resources/js/app.ts").
// - Paths are relative to the package root because npm scripts run there.
export default defineConfig({
    base: './',
    publicDir: false,
    plugins: [vue(), tailwindcss()],
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        manifest: true,
        assetsDir: 'assets',
        sourcemap: false,
        rolldownOptions: {
            input: 'resources/js/app.ts',
        },
    },
});
