import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/themes/mskba_dark/css/app.css',
                'resources/themes/mskba_dark/js/app.js',
                'resources/themes/mskba_streetball/css/app.css',
                'resources/themes/mskba_streetball/js/app.js',
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
