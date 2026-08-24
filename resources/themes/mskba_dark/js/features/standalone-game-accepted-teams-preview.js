document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-standalone-recruitment-panel]').forEach((panel) => {
        enhanceAcceptedTeamPreview(panel);
    });
});

function enhanceAcceptedTeamPreview(panel) {
    const statusText = (panel.textContent || '').replace(/\s+/g, ' ').trim();
    const confirmed = statusText.includes('Стороны утверждены');

    panel.querySelectorAll('.form-text').forEach((meta) => {
        const text = (meta.textContent || '').replace(/\s+/g, ' ').trim();
        if (text === 'Предварительно выбрано · Принято') {
            meta.textContent = 'Выбрана организатором · подтверждение не требуется';
        }
    });

    if (confirmed) return;

    const accepted = [];
    panel.querySelectorAll('article').forEach((article) => {
        const meta = article.querySelector('.form-text');
        const strong = article.querySelector('strong');
        if (!meta || !strong) return;

        const metaText = (meta.textContent || '').replace(/\s+/g, ' ').trim();
        if (!metaText.endsWith('· Принято') && !metaText.includes('подтверждение не требуется')) return;

        const name = (strong.textContent || '').trim();
        if (!name || accepted.includes(name)) return;
        accepted.push(name);
    });

    if (accepted.length !== 2) return;

    const scoreboard = document.querySelector('[data-game-scoreboard]');
    if (scoreboard) {
        const teamA = scoreboard.querySelector('.game-scoreboard__team.is-a strong');
        const teamB = scoreboard.querySelector('.game-scoreboard__team.is-b strong');
        if (teamA) teamA.textContent = accepted[0];
        if (teamB) teamB.textContent = accepted[1];

        if (!scoreboard.previousElementSibling?.matches('[data-accepted-team-preview-note]')) {
            const note = document.createElement('div');
            note.dataset.acceptedTeamPreviewNote = '1';
            note.className = 'alert alert-info mb-3';
            note.innerHTML = '<strong>Обе команды согласились участвовать.</strong><br>Осталось утвердить стороны — после этого зафиксируется игровой состав.';
            scoreboard.before(note);
        }
    }

    const rosterCards = Array.from(document.querySelectorAll('.game-roster-side, [data-game-roster-side], .game-lineup-side'));
    rosterCards.slice(0, 2).forEach((card, index) => {
        const title = card.querySelector('h2, h3, strong');
        if (title && accepted[index]) {
            const textNode = Array.from(title.childNodes).find((node) => node.nodeType === Node.TEXT_NODE);
            if (textNode) textNode.textContent = `${accepted[index]} `;
            else title.textContent = accepted[index];
        }

        Array.from(card.querySelectorAll('p, .form-text')).forEach((copy) => {
            if ((copy.textContent || '').trim() === 'Состав пока не указан.') {
                copy.textContent = 'Команда согласилась участвовать. Игровой состав будет зафиксирован после утверждения сторон.';
            }
        });
    });
}
