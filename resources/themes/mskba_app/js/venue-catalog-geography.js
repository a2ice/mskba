// Shared by both venue catalog themes; the server-rendered selection also
// works without JS after submitting a city. Each form owns its own controls.
function syncGeography(city, preserveDistrict = false) {
    const form = city.form;
    const field = form?.querySelector('[data-venue-geography-district-field]');
    const district = form?.querySelector('[data-venue-geography-district]');
    const source = form?.querySelector('[data-venue-geography-options]');
    if (!field || !district || !source) return;
    const previous = preserveDistrict ? district.value : '';
    const cityId = city.value;
    const empty = district.options[0]?.cloneNode(true) ?? new Option('Все округа и районы', '');
    const options = Array.from(source.content.querySelectorAll('option'))
        .filter(option => cityId && option.dataset.cityId === cityId)
        .map(option => option.cloneNode(true));
    district.replaceChildren(empty, ...options);
    district.value = options.some(option => option.value === previous) ? previous : '';
    district.disabled = !cityId;
    field.hidden = !cityId;
}

document.addEventListener('change', event => {
    if (event.target?.matches('[data-venue-geography-city]')) {
        syncGeography(event.target);
    }
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-venue-geography-city]').forEach(city => syncGeography(city, true));
});
