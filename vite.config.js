import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        vue(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/themes/mskba_dark/css/app.css',
                'resources/themes/mskba_dark/js/app.js',
                'resources/themes/mskba_streetball/css/app.css',
                'resources/themes/mskba_streetball/js/app.js',
                'resources/themes/mskba_app/css/app.css',
                'resources/themes/mskba_app/js/app.js',
                'resources/themes/mskba_app/js/inertia.js',
                'resources/themes/blank/css/app.css',
                'resources/themes/blank/js/app.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
