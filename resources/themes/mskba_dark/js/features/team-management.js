const root = document.querySelector('[data-team-management]');

if (root) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const errorBox = root.querySelector('[data-team-management-error]');
    const successBox = root.querySelector('[data-team-management-success]');
    const message = (text, error = false) => {
        const target = error ? errorBox : successBox;
        const other = error ? successBox : errorBox;
        if (other) other.hidden = true;
        if (target) { target.textContent = text; target.hidden = false; }
    };
    const request = async (url, method, body) => {
        const response = await fetch(url, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || 'Не удалось сохранить изменения.');
        return payload;
    };

    root.querySelectorAll('[data-team-roster]').forEach((group) => {
        if (group.dataset.editable !== '1') return;
        let dragged = null;
        const zones = [...group.querySelectorAll('[data-roster-zone]')];
        const starterZone = zones.find((zone) => zone.dataset.rosterZone === 'starter');
        const starterLimit = Number(group.dataset.limit);
        let capacityTimer = null;
        const starterIsFullFor = (player) => player?.closest('[data-roster-zone]') !== starterZone
            && starterZone.querySelectorAll('[data-roster-player]').length >= starterLimit;
        const showStarterCapacityError = () => {
            starterZone.classList.remove('is-dragover');
            starterZone.classList.add('is-capacity-error');
            message(`В основном составе может быть не больше ${starterLimit} игроков. Сначала переместите игрока в запас.`, true);
            clearTimeout(capacityTimer);
            capacityTimer = setTimeout(() => starterZone.classList.remove('is-capacity-error'), 1800);
        };
        const refresh = () => {
            zones.forEach((zone) => {
                const players = zone.querySelectorAll('[data-roster-player]');
                zone.querySelector('.team-roster-dropzone__empty')?.remove();
                if (!players.length) {
                    const empty = document.createElement('p'); empty.className = 'team-roster-dropzone__empty'; empty.textContent = zone.dataset.rosterZone === 'starter' ? 'Перенесите сюда основных игроков' : 'Запас пока пуст'; zone.append(empty);
                }
            });
            group.querySelector('[data-starter-count]').textContent = zones[0].querySelectorAll('[data-roster-player]').length;
            group.querySelector('[data-reserve-count]').textContent = zones[1].querySelectorAll('[data-roster-player]').length;
            group.classList.add('is-dirty');
        };
        group.addEventListener('dragstart', (event) => { dragged = event.target.closest('[data-roster-player]'); dragged?.classList.add('is-dragging'); });
        group.addEventListener('dragend', () => {
            dragged?.classList.remove('is-dragging'); dragged = null;
            zones.forEach((zone) => zone.classList.remove('is-dragover', 'is-capacity-error'));
        });
        zones.forEach((zone) => {
            zone.addEventListener('dragover', (event) => {
                event.preventDefault();
                if (zone === starterZone && starterIsFullFor(dragged)) {
                    zone.classList.remove('is-dragover');
                    zone.classList.add('is-capacity-error');
                    return;
                }
                zone.classList.remove('is-capacity-error');
                zone.classList.add('is-dragover');
            });
            zone.addEventListener('dragleave', () => zone.classList.remove('is-dragover', 'is-capacity-error'));
            zone.addEventListener('drop', (event) => {
                event.preventDefault();
                zone.classList.remove('is-dragover');
                if (!dragged) return;
                if (zone === starterZone && starterIsFullFor(dragged)) { showStarterCapacityError(); return; }
                zone.classList.remove('is-capacity-error'); zone.append(dragged); refresh();
            });
        });
        group.addEventListener('click', (event) => {
            const button = event.target.closest('[data-roster-move]'); if (!button) return;
            const player = button.closest('[data-roster-player]'); const current = player.closest('[data-roster-zone]'); const target = zones.find((zone) => zone !== current);
            if (target === starterZone && starterIsFullFor(player)) { showStarterCapacityError(); return; }
            target.append(player); refresh();
        });
        group.querySelector('[data-roster-save]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget; button.disabled = true;
            try {
                const ids = (zone) => [...zone.querySelectorAll('[data-roster-player]')].map((player) => Number(player.dataset.membershipId));
                const payload = await request(group.dataset.updateUrl, 'PUT', { sport_type: group.dataset.sportType, starter_ids: ids(zones[0]), reserve_ids: ids(zones[1]) });
                group.classList.remove('is-dirty'); message(payload.message);
            } catch (error) { message(error.message, true); } finally { button.disabled = false; }
        });
    });

    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-captain-url]'); if (!button) return;
        button.disabled = true;
        try {
            const payload = await request(button.dataset.captainUrl, 'PATCH', {});
            root.querySelectorAll('[data-captain-label]').forEach((label) => { label.className = ''; label.textContent = 'Игрок'; });
            root.querySelectorAll(`[data-membership-id="${payload.membership_id}"] [data-captain-label]`).forEach((label) => { label.className = 'team-person__captain'; label.innerHTML = '<i class="ti ti-star"></i> Капитан'; });
            root.querySelectorAll('[data-captain-url]').forEach((item) => { item.hidden = false; }); button.hidden = true; message(payload.message);
        } catch (error) { message(error.message, true); button.disabled = false; }
    });

    root.querySelectorAll('[data-team-permissions-form]').forEach((form) => form.addEventListener('submit', async (event) => {
        event.preventDefault(); const button = form.querySelector('[type="submit"]'); button.disabled = true;
        try { const payload = await request(form.dataset.updateUrl, 'PUT', { permissions: new FormData(form).getAll('permissions[]') }); message(payload.message); } catch (error) { message(error.message, true); } finally { button.disabled = false; }
    }));

    const invitation = root.querySelector('[data-team-invitation]');
    if (invitation) {
        const input = invitation.querySelector('[data-team-user-search]'); const idInput = invitation.querySelector('[data-team-user-id]'); const results = invitation.querySelector('[data-team-user-results]'); let timer;
        input.addEventListener('input', () => {
            idInput.value = ''; clearTimeout(timer); const query = input.value.trim(); if (query.length < 2) { results.hidden = true; return; }
            timer = setTimeout(async () => {
                try {
                    const response = await fetch(`${invitation.dataset.searchUrl}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } }); const payload = await response.json(); results.replaceChildren();
                    payload.users.forEach((user) => { const option = document.createElement('button'); option.type = 'button'; option.dataset.userId = user.id; option.dataset.userLabel = user.name; const name = document.createElement('strong'); name.textContent = user.name; const login = document.createElement('span'); login.textContent = `@${user.username}`; option.append(name, login); results.append(option); });
                    if (!payload.users.length) { const empty = document.createElement('p'); empty.textContent = 'Пользователи не найдены'; results.append(empty); } results.hidden = false;
                } catch { results.hidden = true; }
            }, 250);
        });
        results.addEventListener('click', (event) => { const option = event.target.closest('[data-user-id]'); if (!option) return; idInput.value = option.dataset.userId; input.value = option.dataset.userLabel; results.hidden = true; });
        invitation.querySelector('form').addEventListener('submit', async (event) => {
            event.preventDefault(); const form = event.currentTarget; const data = new FormData(form); if (!idInput.value) { message('Выберите пользователя из подсказки.', true); return; }
            const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
            try { const payload = await request(invitation.dataset.storeUrl, 'POST', { user_id: Number(idInput.value), member_type: data.get('member_type'), permissions: data.getAll('permissions[]') }); form.reset(); idInput.value = ''; message(payload.message); } catch (error) { message(error.message, true); } finally { submit.disabled = false; }
        });
    }
}
