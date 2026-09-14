document.addEventListener('click', (event) => {
    const resolve = event.target.closest('[data-creation-resolve]');
    if (resolve) resolve.closest('[data-creation-access]')?.querySelector('[data-creation-recheck]')?.removeAttribute('hidden');
    if (event.target.closest('[data-creation-recheck]')) window.location.reload();
});
