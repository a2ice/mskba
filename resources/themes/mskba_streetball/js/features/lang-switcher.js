const langSwitchers = Array.from(document.querySelectorAll('[data-mskba-lang-switcher]'));

function setActiveLanguage(lang) {
    langSwitchers.forEach((switcher) => {
        const current = switcher.querySelector('[data-mskba-lang-current]');

        if (current) {
            current.textContent = lang;
        }

        switcher.querySelectorAll('[data-mskba-lang-option]').forEach((item) => {
            const isActive = item.dataset.mskbaLangOption === lang;

            item.classList.toggle('is-active', isActive);

            if (isActive) {
                item.setAttribute('aria-current', 'true');
            } else {
                item.removeAttribute('aria-current');
            }
        });
    });
}

function closeLangMenu(langSwitcher) {
    const langToggle = langSwitcher.querySelector('[data-mskba-lang-toggle]');

    langSwitcher.classList.remove('is-open');
    langToggle?.setAttribute('aria-expanded', 'false');
}

langSwitchers.forEach((langSwitcher) => {
    const langToggle = langSwitcher.querySelector('[data-mskba-lang-toggle]');

    if (!langToggle) {
        return;
    }

    const closeCurrentLangMenu = () => {
        langSwitcher.classList.remove('is-open');
        langToggle.setAttribute('aria-expanded', 'false');
    };

    langToggle.addEventListener('click', () => {
        langSwitchers
            .filter((switcher) => switcher !== langSwitcher)
            .forEach(closeLangMenu);

        const isOpen = langSwitcher.classList.toggle('is-open');
        langToggle.setAttribute('aria-expanded', String(isOpen));
    });

    langSwitcher.addEventListener('click', (event) => {
        const option = event.target.closest('[data-mskba-lang-option]');

        if (!option) {
            return;
        }

        event.preventDefault();

        if (option.dataset.mskbaLangOption) {
            setActiveLanguage(option.dataset.mskbaLangOption);
        }

        closeCurrentLangMenu();
    });

    document.addEventListener('click', (event) => {
        if (!langSwitcher.contains(event.target)) {
            closeCurrentLangMenu();
        }
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeCurrentLangMenu();
        }
    });
});
