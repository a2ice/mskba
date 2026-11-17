/* MSKBA Classic progressive enhancements.
 * Native selects remain native. Only their visual chevron is added,
 * without replacing keyboard navigation, options, validation or change events.
 */
(() => {
    'use strict';

    if (document.documentElement.dataset.designTheme !== 'mskba_classic') {
        return;
    }

    const rootSelector = '.mskba-page-builder';
    const selector = 'select:not([multiple]):not([size]):not([data-classic-plain-select])';

    const enhance = (root) => {
        for (const select of root.querySelectorAll(selector)) {
            if (select.closest('.mskba-classic-select, .select-control')) {
                continue;
            }
            const parent = select.parentElement;
            if (!parent) continue;

            const wrapper = document.createElement('span');
            wrapper.className = 'mskba-classic-select';
            select.before(wrapper);
            wrapper.append(select);

            const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            chevron.setAttribute('viewBox', '0 0 24 24');
            chevron.setAttribute('width', '20');
            chevron.setAttribute('height', '20');
            chevron.setAttribute('fill', 'none');
            chevron.setAttribute('stroke', 'currentColor');
            chevron.setAttribute('stroke-width', '2');
            chevron.setAttribute('stroke-linecap', 'round');
            chevron.setAttribute('stroke-linejoin', 'round');
            chevron.setAttribute('aria-hidden', 'true');
            chevron.setAttribute('focusable', 'false');
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', 'M6 9l6 6 6-6');
            chevron.append(path);
            wrapper.append(chevron);
        }
    };

    const mount = () => {
        const root = document.querySelector(rootSelector);
        if (!root) return;

        enhance(root);
        // Page Builder integrations may inject controls later. This explicit
        // event avoids continuous DOM observation on long animated pages.
        document.addEventListener('mskba:classic:refresh', () => enhance(root));
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount, { once: true });
    } else {
        mount();
    }
})();
