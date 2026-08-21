export function setupBalancedFormation(root) {
    if (!(root instanceof HTMLElement) || root.dataset.balancedFormationReady === '1') return;

    const form = root.querySelector('[data-balanced-formation-form], [data-tournament-formation-form]');
    const preview = root.querySelector('[data-balanced-formation-preview], [data-tournament-formation-preview]');
    const apply = root.querySelector('[data-balanced-formation-apply], [data-tournament-formation-apply]');
    const message = root.querySelector('[data-balanced-formation-message], [data-tournament-formation-message]');
    if (!(form instanceof HTMLFormElement) || !(preview instanceof HTMLElement) || !(apply instanceof HTMLButtonElement)) return;

    root.dataset.balancedFormationReady = '1';
    const allowLogoUpload = root.dataset.allowLogoUpload !== '0';
    const reloadOnApply = root.dataset.reloadOnApply === '1';
    let state = null;
    let dirty = false;
    let dragged = null;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const notify = (text, error = false) => {
        if (!(message instanceof HTMLElement)) return;
        message.hidden = false;
        message.textContent = text;
        message.className = `alert mt-3 ${error ? 'alert-danger' : 'alert-success'}`;
    };
    const playersLabel = (count) => {
        const mod100 = count % 100;
        const mod10 = count % 10;
        if (mod100 >= 11 && mod100 <= 14) return `${count} игроков`;
        if (mod10 === 1) return `${count} игрок`;
        if (mod10 >= 2 && mod10 <= 4) return `${count} игрока`;
        return `${count} игроков`;
    };
    const metrics = (zone) => {
        const players = [...zone.querySelectorAll('[data-formation-player]')];
        const score = players.reduce((sum, player) => sum + Number(player.dataset.score), 0) / Math.max(1, players.length);
        const coverage = players.reduce((sum, player) => sum + Number(player.dataset.coverage), 0) / Math.max(1, players.length);
        const metricsNode = zone.closest('[data-formation-team]')?.querySelector('[data-formation-metrics]');
        if (metricsNode) metricsNode.textContent = `Средний рейтинг ${score.toFixed(3)} · данные ${Math.round(coverage * 100)}% · ${playersLabel(players.length)}`;
    };
    const featureGroup = (title, features, id) => {
        const group = document.createElement('section');
        group.className = 'tournament-formation-player__feature-group';
        const content = document.createElement('div');
        content.id = id;
        content.hidden = true;
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'tournament-formation-player__group-toggle';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-controls', id);
        const filledCount = features.filter((feature) => feature.filled).length;
        toggle.innerHTML = `<span>${title}</span><small>${filledCount}/${features.length}</small><i class="ti ti-chevron-down" aria-hidden="true"></i>`;
        const list = document.createElement('dl');
        list.className = 'tournament-formation-player__feature-list';
        features.forEach((feature) => {
            const label = document.createElement('dt');
            label.textContent = feature.label;
            const value = document.createElement('dd');
            value.textContent = feature.filled ? (feature.value ?? '—') : '—';
            value.classList.toggle('is-missing', !feature.filled);
            list.append(label, value);
        });
        content.append(list);
        group.append(toggle, content);
        toggle.addEventListener('click', () => {
            content.hidden = !content.hidden;
            toggle.setAttribute('aria-expanded', String(!content.hidden));
        });
        return group;
    };
    const render = () => {
        if (!state?.teams) return;
        preview.replaceChildren(...state.teams.map((team) => {
            const column = document.createElement('div');
            column.className = 'col-lg-6';
            column.dataset.formationTeam = String(team.number);
            const card = document.createElement('section');
            card.className = 'border rounded p-3 h-100';

            const title = document.createElement('input');
            title.type = 'text';
            title.className = 'form-control tournament-formation-team__name';
            title.value = team.name ?? `Команда ${team.number}`;
            title.maxLength = 150;
            title.required = true;
            title.dataset.formationTeamName = '';
            title.setAttribute('aria-label', `Название команды ${team.number}`);

            const identity = document.createElement('div');
            identity.className = 'tournament-formation-team__identity';
            const logoPicker = document.createElement('details');
            logoPicker.className = 'tournament-formation-logo-picker';
            const logoToggle = document.createElement('summary');
            logoToggle.setAttribute('aria-label', `Выбрать логотип команды ${team.number}`);
            const logoImage = document.createElement('img');
            let logoPreset = team.logo_preset ?? `crest-${String((team.number - 1) % 15).padStart(2, '0')}`;
            logoImage.src = `/images/tournament-team-logos/${logoPreset}.webp`;
            logoImage.alt = '';
            logoToggle.append(logoImage);
            logoPicker.append(logoToggle);

            const logoOptions = document.createElement('div');
            logoOptions.className = 'tournament-formation-logo-picker__options';
            Array.from({ length: 15 }, (_, index) => `crest-${String(index).padStart(2, '0')}`).forEach((preset) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'tournament-formation-logo-picker__option';
                option.setAttribute('aria-label', `Выбрать эмблему ${preset}`);
                option.innerHTML = `<img src="/images/tournament-team-logos/${preset}.webp" alt="">`;
                option.addEventListener('click', () => {
                    logoPreset = preset;
                    column.dataset.formationLogoPreset = preset;
                    logoImage.src = `/images/tournament-team-logos/${preset}.webp`;
                    const logoFile = column.querySelector('[data-formation-team-logo]');
                    if (logoFile instanceof HTMLInputElement) logoFile.value = '';
                    logoPicker.open = false;
                });
                logoOptions.append(option);
            });
            if (allowLogoUpload) {
                const logoFile = document.createElement('input');
                logoFile.type = 'file';
                logoFile.accept = 'image/jpeg,image/png,image/webp';
                logoFile.hidden = true;
                logoFile.dataset.formationTeamLogo = '';
                const upload = document.createElement('button');
                upload.type = 'button';
                upload.className = 'btn btn--secondary btn--sm';
                upload.textContent = 'Загрузить свой';
                upload.addEventListener('click', () => logoFile.click());
                logoFile.addEventListener('change', () => {
                    const file = logoFile.files?.[0];
                    if (!file) return;
                    logoImage.src = URL.createObjectURL(file);
                    logoPicker.open = false;
                });
                logoOptions.append(upload, logoFile);
            }
            logoPicker.append(logoOptions);
            identity.append(logoPicker, title);

            const info = document.createElement('p');
            info.className = 'text-muted tournament-formation-team__metrics';
            info.dataset.formationMetrics = '';
            const zone = document.createElement('div');
            zone.className = 'team-roster-dropzone';
            zone.dataset.formationZone = String(team.number);
            zone.addEventListener('dragover', (event) => event.preventDefault());
            zone.addEventListener('drop', (event) => {
                event.preventDefault();
                if (!dragged) return;
                zone.append(dragged);
                dirty = true;
                [...preview.querySelectorAll('[data-formation-zone]')].forEach(metrics);
            });

            (team.players ?? []).forEach((player) => {
                const item = document.createElement('article');
                item.className = 'team-person team-person--player';
                item.draggable = true;
                item.dataset.formationPlayer = String(player.id);
                item.dataset.score = String(player.score ?? 0);
                item.dataset.coverage = String(player.coverage ?? 0);
                const body = document.createElement('div');
                body.className = 'tournament-formation-player__body';
                const name = document.createElement('strong');
                name.textContent = player.name || player.username || `Игрок #${player.id}`;
                const detailsId = `formation-player-details-${team.number}-${player.id}`;
                const meta = document.createElement('button');
                meta.type = 'button';
                meta.className = 'tournament-formation-player__summary';
                meta.setAttribute('aria-expanded', 'false');
                meta.setAttribute('aria-controls', detailsId);
                meta.innerHTML = `<span>${player.primary_position || 'unknown'} · ${Math.round(Number(player.coverage ?? 0) * 100)}% данных</span><i class="ti ti-chevron-down" aria-hidden="true"></i>`;
                const details = document.createElement('div');
                details.id = detailsId;
                details.className = 'tournament-formation-player__details';
                details.hidden = true;
                const physicalKeys = new Set(['height_cm', 'weight_kg', 'experience_years', 'body_type']);
                const features = player.features ?? [];
                details.append(
                    featureGroup('Игровые показатели', features.filter((feature) => !physicalKeys.has(feature.key)), `${detailsId}-game`),
                    featureGroup('Физические данные', features.filter((feature) => physicalKeys.has(feature.key)), `${detailsId}-physical`),
                );
                body.append(name, meta, details);
                meta.addEventListener('click', () => {
                    details.hidden = !details.hidden;
                    meta.setAttribute('aria-expanded', String(!details.hidden));
                });
                const actions = document.createElement('div');
                actions.className = 'team-person__actions';
                const move = document.createElement('button');
                move.type = 'button';
                move.className = 'team-person__icon-action team-person__move';
                move.setAttribute('aria-label', 'Переместить в следующую команду');
                move.innerHTML = '<i class="ti ti-arrows-exchange" aria-hidden="true"></i>';
                actions.append(move);
                item.append(body, actions);
                move.addEventListener('click', () => {
                    const zones = [...preview.querySelectorAll('[data-formation-zone]')];
                    const current = item.closest('[data-formation-zone]');
                    const next = zones[(zones.indexOf(current) + 1) % zones.length];
                    next?.append(item);
                    dirty = true;
                    zones.forEach(metrics);
                });
                item.addEventListener('dragstart', () => { dragged = item; });
                item.addEventListener('dragend', () => { dragged = null; });
                zone.append(item);
            });

            card.append(identity, info, zone);
            column.dataset.formationLogoPreset = logoPreset;
            column.append(card);
            queueMicrotask(() => metrics(zone));
            return column;
        }));
        apply.hidden = false;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (dirty && !window.confirm('Новый расчёт сбросит ручные перемещения. Продолжить?')) return;
        const payload = Object.fromEntries(new FormData(form));
        try {
            const response = await fetch(root.dataset.previewUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(payload),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message ?? 'Не удалось сформировать команды.');
            state = data;
            dirty = false;
            render();
            notify('Черновик сформирован.');
        } catch (error) {
            notify(error.message, true);
        }
    });

    apply.addEventListener('click', async () => {
        if (!state) return;
        const teamElements = [...preview.querySelectorAll('[data-formation-team]')];
        const emptyName = teamElements.map((team) => team.querySelector('[data-formation-team-name]')).find((input) => !input?.value.trim());
        if (emptyName instanceof HTMLInputElement) {
            emptyName.focus();
            notify('Укажите название каждой команды.', true);
            return;
        }
        const payload = new FormData();
        payload.append('pool_fingerprint', state.pool_fingerprint);
        teamElements.forEach((team, index) => {
            payload.append(`teams[${index}][number]`, team.dataset.formationTeam);
            payload.append(`teams[${index}][name]`, team.querySelector('[data-formation-team-name]').value.trim());
            payload.append(`teams[${index}][logo_preset]`, team.dataset.formationLogoPreset);
            [...team.querySelectorAll('[data-formation-player]')].forEach((player, playerIndex) => {
                payload.append(`teams[${index}][user_ids][${playerIndex}]`, player.dataset.formationPlayer);
            });
            const logo = team.querySelector('[data-formation-team-logo]');
            if (allowLogoUpload && logo instanceof HTMLInputElement && logo.files?.[0]) {
                payload.append(`teams[${index}][logo]`, logo.files[0]);
            }
        });
        try {
            const response = await fetch(root.dataset.applyUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: payload,
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message ?? 'Не удалось утвердить команды.');
            notify(data.message ?? 'Команды утверждены.');
            if (reloadOnApply) window.location.reload();
            else window.location.reload();
        } catch (error) {
            notify(error.message, true);
        }
    });
}
