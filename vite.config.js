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
        host: '192.168.56.56', // Replace with your Homestead IP
        https: false,
        hmr: {
            host: '192.168.56.56', // Replace with your Homestead IP
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
