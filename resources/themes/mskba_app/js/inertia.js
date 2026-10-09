import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    title: (title) => title ? `${title} · MSKBA` : 'MSKBA',
    resolve: (name) => {
        const pages = import.meta.glob('./pages/*.vue', { eager: true });
        const page = pages[`./pages/${name}.vue`];
        if (!page) throw new Error(`Unknown MSKBA App page: ${name}`);
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
