import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel(['resources/css/app.css', 'resources/js/app.js']),
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

// import { defineConfig } from 'vite';
// import laravel from 'laravel-vite-plugin';

// export default defineConfig({
//     plugins: [
//         laravel({
//             input: [
//                 'resources/css/app.css',
//                 'resources/js/app.js',
//             ],
//             refresh: true,
//         }),
//     ],

//     server: {
//         watch: {
//             ignored: ['**/storage/**', '**/vendor/**'],
//         },
//     },

//     build: {
//         rollupOptions: {
//             external: [
//                 /^storage\/.*/,
//             ],
//         },
//     },
// });
