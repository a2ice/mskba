// Presentation-only helper: logo URLs are private, same-origin media endpoints.
// Never render an arbitrary remote URL returned by an API response.
export function resolvePlayerLogoPreview(logos, selectedLogoId, origin, placeholder) {
    const empty = { src: placeholder, alt: 'Логотип не выбран', label: 'Логотип не выбран' };
    const id = Number(selectedLogoId);
    if (!Number.isSafeInteger(id) || id <= 0 || !Array.isArray(logos)) return empty;

    const logo = logos.find(item => Number(item?.id) === id);
    if (!logo || typeof logo.url !== 'string') return empty;
    try {
        const url = new URL(logo.url, origin);
        if (url.origin !== origin || !['http:', 'https:'].includes(url.protocol)) return empty;
        const label = String(logo.name || 'Мой логотип').trim() || 'Мой логотип';
        return { src: url.href, alt: label, label };
    } catch {
        return empty;
    }
}

/**
 * The selected team logo is the team's actual public Media image, not the
 * default placeholder or an unrelated private uploaded logo.
 */
export function resolvePlayerTeamLogoPreview(team, origin, placeholder) {
    if (!team || !team.id || !team.logo_url) {
        return { src: placeholder, alt: 'Логотип не выбран', label: 'Логотип не выбран' };
    }
    return resolvePlayerLogoPreview([{
        id: team.id,
        url: team.logo_url,
        name: 'Логотип команды ' + (team.name || ''),
    }], team.id, origin, placeholder);
}
