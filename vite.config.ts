import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { VitePWA } from 'vite-plugin-pwa';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.ts',
                'resources/js/offline.ts',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js',
            filename: 'sw.ts',
            outDir: 'public/build',
            buildBase: '/build/',
            injectRegister: null,
            registerType: 'prompt',
            manifestFilename: 'manifest.webmanifest',
            injectManifest: {
                globPatterns: ['**/*.{js,css,woff2,svg,png}'],
                modifyURLPrefix: { '': '/build/' },
            },
            integration: {
                // The plugin precaches the web manifest by its bare filename, which the
                // root-scoped /sw.js resolves to /manifest.webmanifest: a 404 that fails
                // install. modifyURLPrefix never reaches this entry, so prefix it here.
                beforeBuildServiceWorker(options) {
                    options.injectManifest.additionalManifestEntries =
                        options.injectManifest.additionalManifestEntries?.map(
                            (entry) =>
                                typeof entry !== 'string' &&
                                entry.url === options.manifestFilename
                                    ? {
                                          ...entry,
                                          url: `${options.buildBase}${entry.url}`,
                                      }
                                    : entry,
                        );
                },
            },
            manifest: {
                id: '/',
                name: 'Snuggy Parking',
                short_name: 'Snuggy',
                start_url: '/',
                scope: '/',
                display: 'standalone',
                theme_color: '#0f766e',
                background_color: '#ffffff',
                icons: [
                    {
                        src: '/icons/icon-192.png',
                        sizes: '192x192',
                        type: 'image/png',
                    },
                    {
                        src: '/icons/icon-512.png',
                        sizes: '512x512',
                        type: 'image/png',
                    },
                    {
                        src: '/icons/icon-maskable-512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'maskable',
                    },
                ],
            },
        }),
    ]),
    define: {
        // Bumps the precached /offline page (P6) on every build.
        __OFFLINE_REV__: JSON.stringify(Date.now().toString()),
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
            'thoughts/**',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
