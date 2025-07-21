import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/plaid-link.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
    host: '127.0.0.1',
    port: 5173,
    hmr: {
        host: '127.0.0.1',
    },
    watch: {
        usePolling: true,
        ignored: ['**/storage/**', '**/vendor/**'],
    },
    },

    build: {
        rollupOptions: {
            external: [
                /^storage\/.*/,
            ],
        },
    },
});
