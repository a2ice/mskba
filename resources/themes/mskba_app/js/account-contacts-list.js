// Shared fragment refresh for contact creation and confirmation flows.
export async function refreshAccountContactsList() {
    const current = document.querySelector('[data-account-contacts-list]');
    if (!current) throw new Error('Список контактов недоступен.');

    const response = await fetch(current.dataset.accountContactsListUrl, {
        headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!response.ok) throw new Error('Не удалось обновить список контактов.');

    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    const updated = page.querySelector('[data-account-contacts-list]');
    if (!updated) throw new Error('Не удалось получить обновлённый список контактов.');

    current.replaceWith(updated);
    document.dispatchEvent(new Event('mskba:contacts-list-updated'));
}
