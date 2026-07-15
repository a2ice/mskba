document.addEventListener('DOMContentLoaded', () => {
    const options = Array.from(document.querySelectorAll('[data-venue-duplicate-option]'));
    const openButton = document.querySelector('[data-venue-duplicates-merge-open]');
    const modal = document.querySelector('[data-venue-duplicates-merge-modal]');
    const form = document.querySelector('[data-venue-duplicates-merge-form]');

    if (options.length && openButton && modal && form) {
        initMerge();
    }

    initPreview();
});

function initMerge() {
    const options = Array.from(document.querySelectorAll('[data-venue-duplicate-option]'));
    const openButton = document.querySelector('[data-venue-duplicates-merge-open]');
    const modal = document.querySelector('[data-venue-duplicates-merge-modal]');
    const form = document.querySelector('[data-venue-duplicates-merge-form]');

    const canonicalInput = form.querySelector('[data-venue-duplicates-canonical-id]');
    const selectedInputs = form.querySelector('[data-venue-duplicates-selected-inputs]');
    const canonicalOptions = form.querySelector('[data-venue-duplicates-canonical-options]');

    let activeGroupId = null;

    options.forEach((option) => {
        option.addEventListener('change', () => {
            syncSelection(option);
        });
    });

    openButton.addEventListener('click', () => {
        const selectedOptions = selectedVenueOptions();

        if (selectedOptions.length < 2) {
            return;
        }

        renderSelectedInputs(selectedOptions);
        renderCanonicalOptions(selectedOptions);
        syncCanonicalInput();

        modal.hidden = false;
        document.body.classList.add('modal-open');
    });

    modal.querySelectorAll('[data-venue-duplicates-merge-close]').forEach((closeButton) => {
        closeButton.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('modal-open');
    }

    function syncSelection(changedOption) {
        const selectedOptions = selectedVenueOptions();

        if (selectedOptions.length === 0) {
            activeGroupId = null;
            options.forEach((option) => {
                option.disabled = false;
            });
            openButton.disabled = true;

            return;
        }

        activeGroupId = changedOption.checked
            ? changedOption.dataset.groupId
            : selectedOptions[0].dataset.groupId;

        options.forEach((option) => {
            option.disabled = option.dataset.groupId !== activeGroupId;
        });

        openButton.disabled = !canOpenMerge(selectedOptions);
    }

    function selectedVenueOptions() {
        return options.filter((option) => option.checked);
    }

    function renderSelectedInputs(selectedOptions) {
        selectedInputs.innerHTML = '';

        selectedOptions.forEach((option) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'venue_ids[]';
            input.value = option.dataset.venueId;
            selectedInputs.appendChild(input);
        });
    }

    function renderCanonicalOptions(selectedOptions) {
        canonicalOptions.innerHTML = '';
        const groupHasConfirmed = selectedOptions.some((selectedOption) => selectedOption.dataset.groupHasConfirmed === '1');

        selectedOptions.forEach((option, index) => {
            const label = document.createElement('label');
            label.className = 'admin-merge-choice__item';

            const input = document.createElement('input');
            input.type = 'radio';
            input.name = 'canonical_choice';
            input.value = option.dataset.venueId;
            input.disabled = groupHasConfirmed && option.dataset.isConfirmed !== '1';
            input.checked = groupHasConfirmed
                ? option.dataset.isConfirmed === '1'
                : index === 0;
            input.addEventListener('change', syncCanonicalInput);

            const body = document.createElement('span');
            const title = document.createElement('strong');
            const address = document.createElement('small');
            const creator = document.createElement('small');
            const hint = document.createElement('em');

            title.textContent = option.dataset.venueTitle || '';
            address.textContent = option.dataset.venueAddress || '';
            creator.textContent = option.dataset.venueCreator || '';
            hint.textContent = 'Оставить главной площадкой';

            body.append(title, address, creator, hint);
            label.append(input, body);
            canonicalOptions.appendChild(label);
        });
    }

    function syncCanonicalInput() {
        const checkedCanonical = canonicalOptions.querySelector('input[name="canonical_choice"]:checked');

        if (!checkedCanonical) {
            return;
        }

        canonicalInput.value = checkedCanonical.value;
    }

    function canOpenMerge(selectedOptions) {
        if (selectedOptions.length < 2) {
            return false;
        }

        if (!selectedOptions.some((option) => option.dataset.groupHasConfirmed === '1')) {
            return true;
        }

        return selectedOptions.some((option) => option.dataset.isConfirmed === '1');
    }
}

function initPreview() {
    const previewModal = document.querySelector('[data-venue-duplicates-preview-modal]');

    if (!previewModal) {
        return;
    }

    document.querySelectorAll('[data-venue-duplicates-preview-open]').forEach((button) => {
        button.addEventListener('click', () => {
            setPreviewText('title', button.dataset.venueTitle || '');
            setPreviewText('status', button.dataset.venueStatus || '');
            setPreviewText('type', button.dataset.venueType || '');
            setPreviewText('address', button.dataset.venueAddress || '');
            setPreviewText('creator', button.dataset.venueCreator || '');
            setPreviewText('createdAt', button.dataset.venueCreatedAt || '');
            setPreviewText('description', button.dataset.venueDescription || '');

            previewModal.hidden = false;
            document.body.classList.add('modal-open');
        });
    });

    previewModal.querySelectorAll('[data-venue-duplicates-preview-close]').forEach((closeButton) => {
        closeButton.addEventListener('click', closePreviewModal);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !previewModal.hidden) {
            closePreviewModal();
        }
    });

    function closePreviewModal() {
        previewModal.hidden = true;
        document.body.classList.remove('modal-open');
    }

    function setPreviewText(key, value) {
        const target = previewModal.querySelector(`[data-venue-preview-${kebabCase(key)}]`);

        if (target) {
            target.textContent = value;
        }
    }

    function kebabCase(value) {
        return value.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`);
    }
}
