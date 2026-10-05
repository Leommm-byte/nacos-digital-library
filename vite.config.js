import { readdirSync, readFileSync } from 'node:fs';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/**
 * Copies the data files PDF.js loads on demand (fonts for PDFs that don't
 * embed theirs, character maps, image decoders, colour profiles) into
 * public/build/pdfjs, with stable names, so the reader is fully self-hosted.
 */
function pdfjsAssets() {
    const root = 'node_modules/pdfjs-dist';

    return {
        name: 'pdfjs-assets',
        apply: 'build',
        generateBundle() {
            for (const dir of ['standard_fonts', 'cmaps', 'wasm', 'iccs']) {
                for (const name of readdirSync(`${root}/${dir}`)) {
                    this.emitFile({
                        type: 'asset',
                        fileName: `pdfjs/${dir}/${name}`,
                        source: readFileSync(`${root}/${dir}/${name}`),
                    });
                }
            }
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        pdfjsAssets(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
