import { readdirSync, readFileSync } from 'node:fs';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/**
 * Copies Tesseract (on-device OCR for scanned uploads) into
 * public/build/tesseract: its worker, the LSTM builds of its engine (with
 * and without SIMD; the browser picks one) and the English model.
 */
function tesseractAssets() {
    const files = {
        'tesseract/worker.min.js': 'node_modules/tesseract.js/dist/worker.min.js',
        'tesseract/lang/eng.traineddata.gz': 'node_modules/@tesseract.js-data/eng/4.0.0_best_int/eng.traineddata.gz',
    };
    for (const name of readdirSync('node_modules/tesseract.js-core')) {
        if (/^tesseract-core(-simd|-relaxedsimd)?-lstm\.(wasm|wasm\.js|js)$/.test(name)) {
            files[`tesseract/core/${name}`] = `node_modules/tesseract.js-core/${name}`;
        }
    }

    return {
        name: 'tesseract-assets',
        apply: 'build',
        generateBundle() {
            for (const [fileName, source] of Object.entries(files)) {
                this.emitFile({ type: 'asset', fileName, source: readFileSync(source) });
            }
        },
    };
}

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
        tesseractAssets(),
    ],
    build: {
        // The local stack rebuilds on every save (docker/assets/watch.sh).
        // Emptying public/build first left a moment with no manifest, and a
        // page opened then failed with "Unable to locate file in Vite
        // manifest". Watching keeps the old files until the new ones are
        // written; watch.sh clears the folder when the container starts.
        emptyOutDir: !process.argv.includes('--watch'),
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
