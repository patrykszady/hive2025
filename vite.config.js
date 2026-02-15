import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const vitePort = Number(env.VITE_PORT || 5173);

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/plaid-link.js'],
                refresh: true,
            }),
            tailwindcss(),
        ],
        server: {
            host: true,
            port: vitePort,
            strictPort: true,
            hmr: {
                host: env.VITE_HMR_HOST || '127.0.0.1',
                port: Number(env.VITE_HMR_PORT || vitePort),
                clientPort: Number(env.VITE_HMR_CLIENT_PORT || vitePort),
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
    };
});
