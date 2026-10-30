const workspace = document.querySelector('[data-player-workspace]');
if (workspace) {
    const preview = workspace.querySelector('[data-player-preview]');
    const form = workspace.querySelector('[data-player-physical-form]');
    const height = form?.querySelector('[data-physical-height]');
    const weight = form?.querySelector('[data-physical-weight]');
    const body = form?.querySelector('[data-physical-body-type]');
    const stage = workspace.querySelector('[data-player-stage]');
    const figure = workspace.querySelector('[data-player-figure]');


    // Gender is a single canonical profile attribute, never a separate player field.
    // Keep the explanatory step and the full profile editor in native top-layer dialogs.
    const genderInfo = workspace.querySelector('[data-player-gender-dialog]');
    const personalInfo = workspace.querySelector('[data-player-personal-dialog]');
    const genderTrigger = workspace.querySelector('[data-player-gender-open]');
    if (genderInfo && personalInfo && genderTrigger) {
        const focusHeading = dialog => {
            dialog.showModal();
            dialog.querySelector('h2[tabindex="-1"]')?.focus({preventScroll:true});
        };
        genderTrigger.addEventListener('click', () => focusHeading(genderInfo));
        genderInfo.querySelectorAll('[data-player-gender-close]').forEach(button =>
            button.addEventListener('click', () => genderInfo.close()));
        personalInfo.querySelectorAll('[data-player-personal-close]').forEach(button =>
            button.addEventListener('click', () => personalInfo.close()));
        genderInfo.querySelector('[data-player-personal-open]')?.addEventListener('click', () => {
            genderInfo.close();
            focusHeading(personalInfo);
        });
        personalInfo.addEventListener('close', () => {
            if (genderTrigger.isConnected) genderTrigger.focus({preventScroll:true});
        });
    }

    // Task 057: side selection is a UI adapter around the existing
    // character.attributes[] wire format. No new persistence fields.
    const equipmentDialog = workspace.querySelector('[data-player-equipment-dialog]');
    if (form && equipmentDialog) {
        const equipmentTitle = equipmentDialog.querySelector('[data-player-equipment-title]');
        const leftInput = equipmentDialog.querySelector('[data-player-equipment-left]');
        const rightInput = equipmentDialog.querySelector('[data-player-equipment-right]');
        let activeEquipmentGroup = null;
        let equipmentReturnFocus = null;

        const valuesFor = group =>
            Array.from(group.querySelectorAll('[data-player-equipment-values] input'))
                .map(input => input.value);

        const updateEquipmentGroup = (group, selected) => {
            const leftValue = group.dataset.equipmentLeft;
            const rightValue = group.dataset.equipmentRight;
            const left = selected.includes(leftValue);
            const right = selected.includes(rightValue);
            const toggle = group.querySelector('[data-player-equipment-toggle]');
            const suffix = group.querySelector('[data-player-equipment-suffix]');
            const edit = group.querySelector('[data-player-equipment-edit]');
            const holder = group.querySelector('[data-player-equipment-values]');
            toggle.checked = left || right;
            suffix.textContent = left && right ? ' (л/п)' : left ? ' (л)' : right ? ' (п)' : '';
            edit.hidden = !(left || right);
            holder.replaceChildren();
            for (const value of [left && leftValue, right && rightValue].filter(Boolean)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'character[attributes][]';
                input.value = value;
                holder.appendChild(input);
            }
        };

        const openEquipmentDialog = (group, trigger) => {
            if (equipmentDialog.open) return;
            activeEquipmentGroup = group;
            equipmentReturnFocus = trigger;
            const values = valuesFor(group);
            leftInput.checked = values.includes(group.dataset.equipmentLeft);
            rightInput.checked = values.includes(group.dataset.equipmentRight);
            equipmentTitle.textContent = group.dataset.equipmentName + ': выбор стороны';
            equipmentDialog.showModal();
            equipmentTitle.focus({preventScroll:true});
        };

        workspace.querySelectorAll('[data-player-equipment-group]').forEach(group => {
            const toggle = group.querySelector('[data-player-equipment-toggle]');
            const edit = group.querySelector('[data-player-equipment-edit]');
            toggle.addEventListener('change', () => {
                if (toggle.checked) {
                    // A click does not commit a side until the user presses Apply.
                    toggle.checked = false;
                    openEquipmentDialog(group, toggle);
                } else {
                    // Clearing the common checkbox removes both legacy side values.
                    updateEquipmentGroup(group, []);
                }
            });
            edit.addEventListener('click', () => openEquipmentDialog(group, edit));
        });

        equipmentDialog.querySelector('[data-player-equipment-apply]')?.addEventListener('click', () => {
            if (!activeEquipmentGroup) return;
            const selected = [];
            if (leftInput.checked) selected.push(activeEquipmentGroup.dataset.equipmentLeft);
            if (rightInput.checked) selected.push(activeEquipmentGroup.dataset.equipmentRight);
            updateEquipmentGroup(activeEquipmentGroup, selected);
            equipmentDialog.close();
        });
        equipmentDialog.querySelectorAll('[data-player-equipment-cancel]').forEach(button =>
            button.addEventListener('click', () => equipmentDialog.close()));
        equipmentDialog.addEventListener('close', () => {
            const returnTo = equipmentReturnFocus;
            const group = activeEquipmentGroup;
            activeEquipmentGroup = null;
            equipmentReturnFocus = null;
            if (returnTo?.isConnected && !returnTo.hidden) {
                returnTo.focus({preventScroll:true});
            } else {
                // When Apply clears both sides, the Edit control becomes hidden.
                // Return focus to that group's checkbox rather than another group.
                group?.querySelector('[data-player-equipment-toggle]')?.focus({preventScroll:true});
            }
        });
    }

    // Same sticky calculation as the venue sidebar: bottom of tabs plus 15px.
    const playerNav = workspace.querySelector('[data-player-section-nav]');
    if (playerNav) {
        let stickyPending = false;
        const updateSticky = () => {
            stickyPending = false;
            if (!playerNav.getClientRects().length) return;
            const contextBottom = document.querySelector('.app-context-bar')?.getBoundingClientRect().bottom || 0;
            const headerBottom = document.querySelector('.site-header')?.getBoundingClientRect().bottom || 0;
            const boundary = Math.max(0, contextBottom, headerBottom);
            const navRect = playerNav.getBoundingClientRect();
            preview?.style.setProperty('--player-preview-sticky-top',
                Math.ceil(Math.max(navRect.bottom, boundary) + 15) + 'px');
            const top = Number.parseFloat(window.getComputedStyle(playerNav).top) || boundary;
            playerNav.classList.toggle('is-stuck', window.scrollY > 0 && Math.abs(navRect.top - top) <= 2);
        };
        const scheduleSticky = () => {
            if (stickyPending) return;
            stickyPending = true;
            window.requestAnimationFrame(updateSticky);
        };
        window.addEventListener('scroll', scheduleSticky, {passive:true});
        window.addEventListener('resize', scheduleSticky);
        if ('ResizeObserver' in window) new ResizeObserver(scheduleSticky).observe(playerNav);
        let transitionFrame = null;
        window.addEventListener('mskba:header-visibility', () => {
            const until = performance.now() + 340;
            if (transitionFrame !== null) cancelAnimationFrame(transitionFrame);
            const follow = () => {
                scheduleSticky();
                transitionFrame = performance.now() < until ? requestAnimationFrame(follow) : null;
            };
            transitionFrame = requestAnimationFrame(follow);
        });
        scheduleSticky();
    }

    const feedback = workspace.querySelector('[data-player-generation-status]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let lastImage = '';

    workspace.querySelectorAll('[data-player-skill]').forEach(slider => {
        const output = slider.parentElement?.querySelector('output');
        if (!output) return;
        slider.addEventListener('input', () => { output.textContent = slider.value; });
    });

    function updateFigure() {
        if (!form || !stage) return;
        const cm = Number(height?.value || 185);
        const kg = Number(weight?.value || 80);
        const bmi = kg / Math.pow(cm / 100, 2);
        const bodyAdjustment = {slim:-0.09, normal:0, athletic:0, muscular:0.12, stocky:0.15, large:0.20}[body?.value] || 0;
        const width = Math.min(1.4, Math.max(0.78, 1 + (bmi-23)*0.009 + bodyAdjustment));
        const scaleHeight = Math.max(0, Math.min(225, cm));
        stage.style.setProperty('--figure-height', (scaleHeight / 225 * 100) + '%');
        const heightMarker = workspace.querySelector('[data-player-height-marker]');
        if (heightMarker) heightMarker.style.bottom = (scaleHeight / 225 * 100) + '%';
        const heightMarkerLabel = workspace.querySelector('[data-player-height-marker-label]');
        if (heightMarkerLabel) heightMarkerLabel.textContent = cm + ' см';
        stage.style.setProperty('--figure-width', String(width));
        workspace.querySelector('[data-player-height-label]').textContent =
            'Рост: ' + (height?.value ? cm + ' см' : '185 см (пример)');
        workspace.querySelector('[data-player-weight-label]').textContent =
            'Вес: ' + (weight?.value ? kg + ' кг' : 'не указан');
    }
    const heightOutput = workspace.querySelector('[data-physical-height-value]');
    const weightOutput = workspace.querySelector('[data-physical-weight-value]');
    function onPhysicalInput() {
        if (heightOutput) heightOutput.textContent = height.value + ' см';
        if (weightOutput) weightOutput.textContent = weight.value + ' кг';
        updateFigure();
    }
    [height, weight, body].forEach(input => input?.addEventListener('input', onPhysicalInput));
    // The slim height indicator reveals its cm badge on hover or while the height slider has focus.
    height?.addEventListener('focus', () => stage?.classList.add('is-height-focused'));
    height?.addEventListener('blur', () => stage?.classList.remove('is-height-focused'));

    // Drag the orange height line itself. The same native range input owns the
    // value, so drag, keyboard and range-slider edits share one update path.
    const heightMarkerDrag = workspace.querySelector('[data-player-height-marker]');
    const heightScale = heightMarkerDrag?.parentElement;
    let activeHeightPointer = null;
    let heightDragOffsetY = 0;
    const applyPointerHeight = clientY => {
        if (!height || !heightScale) return;
        const rect = heightScale.getBoundingClientRect();
        if (rect.height <= 0) return;
        const proposedCm = Math.round((rect.bottom - clientY) / rect.height * 225);
        const cm = Math.max(Number(height.min), Math.min(Number(height.max), proposedCm));
        if (Number(height.value) === cm) return;
        height.value = String(cm);
        height.dispatchEvent(new Event('input', {bubbles:true}));
    };
    heightMarkerDrag?.addEventListener('pointerdown', event => {
        if (!height || (event.pointerType === 'mouse' && event.button !== 0)) return;
        const bounds = heightScale?.getBoundingClientRect();
        if (!bounds?.height) return;
        event.preventDefault();
        // Preserve the initial marker position when the user grabs the
        // enlarged transparent hit area above or below the 1px line.
        const markerCenterY = bounds.bottom - Number(height.value) / 225 * bounds.height;
        heightDragOffsetY = event.clientY - markerCenterY;
        activeHeightPointer = event.pointerId;
        heightMarkerDrag.setPointerCapture(event.pointerId);
        stage?.classList.add('is-height-dragging');
    });
    heightMarkerDrag?.addEventListener('pointermove', event => {
        if (event.pointerId !== activeHeightPointer) return;
        event.preventDefault();
        applyPointerHeight(event.clientY - heightDragOffsetY);
    });
    const endHeightDrag = event => {
        if (event.pointerId !== activeHeightPointer) return;
        activeHeightPointer = null;
        heightDragOffsetY = 0;
        stage?.classList.remove('is-height-dragging');
        if (heightMarkerDrag?.hasPointerCapture(event.pointerId)) {
            heightMarkerDrag.releasePointerCapture(event.pointerId);
        }
    };
    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(type =>
        heightMarkerDrag?.addEventListener(type, endHeightDrag));

    updateFigure();

    const message = (text, error = false) => {
        if (!feedback) return;
        feedback.textContent = text;
        feedback.classList.toggle('is-error', error);
    };
    const setImage = url => {
        if (!url || !figure) return;
        const target = new URL(url, location.origin);
        if (target.origin !== location.origin) return;
        lastImage = target.href;
        figure.src = lastImage;
        figure.classList.add('is-generated');

    };
    const request = async (payload) => {
        const response = await fetch(form.action, {
            method:'POST', credentials:'same-origin',
            headers:{Accept:'application/json', 'X-CSRF-TOKEN':csrf},
            body:payload,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const detail = data.errors ? Object.values(data.errors).flat()[0] : null;
            throw new Error(detail || data.message || 'Не удалось выполнить операцию.');
        }
        return data;
    };
    const jsonMutation = async body => {
        const payload = new FormData();
        payload.set('_method','PATCH');
        payload.set('_token',csrf);
        Object.entries(body).forEach(([key,value]) => payload.set(key,String(value)));
        return request(payload);
    };
    // The existing pipeline owns image storage, access checks, and billing.
    // The gallery shows only completed images from the authorized history endpoint.
    const gallery = workspace.querySelector('[data-player-avatar-library]');
    const avatarRail = gallery?.querySelector('[data-player-avatar-rail]');
    const avatarCount = gallery?.querySelector('[data-player-avatar-count]');
    const avatarHeading = gallery?.querySelector('[data-player-avatar-heading]');
    const avatarEmpty = gallery?.querySelector('[data-player-avatar-empty]');
    const avatarActions = gallery?.querySelector('[data-player-avatar-actions]');
    const makePrimary = gallery?.querySelector('[data-player-avatar-make-primary]');
    const primaryLabel = gallery?.querySelector('[data-player-avatar-primary-label]');
    const avatarFeedback = gallery?.querySelector('[data-player-avatar-feedback]');
    let availableAvatars = [];
    let selectedAvatarId = null;
    let primaryAvatarId = null;
    let selectingPrimary = false;

    const ownImageUrl = value => {
        if (typeof value !== 'string' || !value) return null;
        try {
            const url = new URL(value, location.origin);
            return url.origin === location.origin ? url.href : null;
        } catch {
            return null;
        }
    };
    const avatarStatus = (status, error = false) => {
        if (!avatarFeedback) return;
        avatarFeedback.textContent = status;
        avatarFeedback.classList.toggle('is-error', error);
    };
    const renderAvatars = () => {
        if (!avatarRail) return;
        avatarRail.replaceChildren();
        const hasAvatars = availableAvatars.length > 0;
        if (avatarCount) avatarCount.textContent = String(availableAvatars.length);
        if (avatarHeading) avatarHeading.hidden = !hasAvatars;
        if (avatarRail) avatarRail.hidden = !hasAvatars;
        if (avatarEmpty) avatarEmpty.hidden = hasAvatars;
        if (avatarActions) avatarActions.hidden = availableAvatars.length === 0;
        if (makePrimary) {
            makePrimary.hidden = !selectedAvatarId || selectedAvatarId === primaryAvatarId;
            makePrimary.disabled = selectingPrimary;
        }
        if (primaryLabel) primaryLabel.hidden = !selectedAvatarId || selectedAvatarId !== primaryAvatarId;
        availableAvatars.forEach((item, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'app-player-workspace__avatar-item';
            button.setAttribute('aria-label', 'Аватар ' + (index + 1) +
                (item.generation_id === primaryAvatarId ? ', основной' : ''));
            button.setAttribute('aria-pressed', String(item.generation_id === selectedAvatarId));
            if (item.generation_id === selectedAvatarId) button.classList.add('is-selected');
            const img = document.createElement('img');
            img.src = item.image_url;
            img.alt = '';
            img.loading = 'lazy';
            button.append(img);
            if (item.generation_id === primaryAvatarId) {
                const badge = document.createElement('span');
                badge.className = 'app-player-workspace__avatar-item-badge';
                badge.textContent = 'Основной';
                button.append(badge);
            }
            button.addEventListener('click', () => {
                selectedAvatarId = item.generation_id;
                setImage(item.image_url);
                avatarStatus('');
                renderAvatars();
                // Rebuilding the list should not discard keyboard focus.
                avatarRail.querySelectorAll('button')[index]?.focus({preventScroll:true});
            });
            avatarRail.append(button);
        });
    };
    const showExisting = async () => {
        if (!form) return;
        try {
            const history = await jsonMutation({mutation:'generation_history'});
            // Keep only own completed entries that expose authorized same-origin image URLs.
            availableAvatars = (Array.isArray(history.generations) ? history.generations : [])
                .filter(item => item.status === 'completed' && item.generation_id && ownImageUrl(item.image_url))
                .map(item => ({generation_id:item.generation_id, image_url:ownImageUrl(item.image_url)}));
            const availableIds = new Set(availableAvatars.map(item => item.generation_id));
            primaryAvatarId = availableIds.has(history.primary_generation_id)
                ? history.primary_generation_id : (availableAvatars[0]?.generation_id || null);
            if (!availableIds.has(selectedAvatarId)) selectedAvatarId = primaryAvatarId;
            const selected = availableAvatars.find(item => item.generation_id === selectedAvatarId);
            if (selected) setImage(selected.image_url);
            renderAvatars();
            const pending = history.generations?.find(item => ['pending','processing'].includes(item.status));
            if (pending) message('Генерация ранее запущена. Обнови страницу позже, чтобы увидеть результат.');
        } catch {
            avatarStatus('Не удалось загрузить библиотеку аватаров.', true);
        }
    };
    makePrimary?.addEventListener('click', async () => {
        if (selectingPrimary || !selectedAvatarId || selectedAvatarId === primaryAvatarId) return;
        selectingPrimary = true;
        avatarStatus('Сохраняем основной аватар…');
        renderAvatars();
        try {
            const result = await jsonMutation({mutation:'generation_primary', generation_id:selectedAvatarId});
            if (result.generation_id !== selectedAvatarId) throw new Error('Не удалось подтвердить выбор.');
            primaryAvatarId = selectedAvatarId;
            avatarStatus('Основной аватар обновлён.');
        } catch (error) {
            avatarStatus(error.message || 'Не удалось выбрать основной аватар.', true);
        } finally {
            selectingPrimary = false;
            renderAvatars();
            if (primaryAvatarId === selectedAvatarId) {
                const index = availableAvatars.findIndex(item => item.generation_id === selectedAvatarId);
                avatarRail?.querySelectorAll('button')[index]?.focus({preventScroll:true});
            }
        }
    });

    async function pollGeneration(statusUrl, id) {
        const target = new URL(statusUrl, location.origin);
        if (target.origin !== location.origin) throw new Error('Неверный адрес генерации.');
        for (let i=0;i<90;i++) {
            await new Promise(resolve => setTimeout(resolve, 2000));
            const response = await fetch(target.href,{credentials:'same-origin',headers:{Accept:'application/json'}});
            if (!response.ok) throw new Error('Не удалось проверить генерацию.');
            const data = await response.json();
            if (data.status === 'failed') throw new Error(data.message || 'Генерация не удалась.');
            if (data.status === 'completed' && data.image_url) {
                setImage(data.image_url);
                try {
                    await jsonMutation({mutation:'generation_primary',generation_id:id});
                } catch { /* Image remains visible even if primary selection fails. */ }
                await showExisting();
                message('Аватар готов и установлен на шкале роста.');
                return;
            }
        }
        message('Генерация ещё продолжается. Результат появится после обновления страницы.');
    }

    const generate = workspace.querySelector('[data-player-generate]');
    const generationOffer = workspace.querySelector('[data-player-generation-offer]');
    const generationFields = generationOffer?.querySelector('[data-player-generation-fields]');
    const offerPrice = generationOffer?.querySelector('[data-player-offer-price]');
    const offerBonus = generationOffer?.querySelector('[data-player-offer-bonus]');
    const offerTotal = generationOffer?.querySelector('[data-player-offer-total]');
    const offerCount = generationOffer?.querySelector('[data-player-offer-count]');
    const offerUnavailable = generationOffer?.querySelector('[data-player-offer-unavailable]');
    const topupDialog = workspace.querySelector('[data-player-topup-dialog]');
    const money = minor => (Number(minor) / 100).toLocaleString('ru-RU', {
        style: 'currency', currency: 'RUB',
    });
    let canGenerate = generate ? !generate.disabled : false;
    const displayOffer = quote => {
        const available = !!quote.can_generate;
        canGenerate = available;
        if (offerPrice) offerPrice.textContent = quote.price_minor === null
            ? 'Недоступна' : money(quote.price_minor);
        if (offerBonus) offerBonus.textContent = money(quote.bonus_balance_minor || 0);
        if (offerTotal) offerTotal.textContent = money(quote.available_minor || 0);
        if (offerCount) offerCount.textContent = quote.price_minor === null
            ? '—' : Number(quote.price_minor) === 0
                ? 'Без оплаты' : String(quote.available_generations ?? 0);
        if (generationFields) generationFields.hidden = !available;
        if (offerUnavailable) {
            offerUnavailable.hidden = available;
            offerUnavailable.textContent = quote.price_minor === null
                ? 'Стоимость генерации временно недоступна.'
                : 'Недостаточно средств для генерации аватара.';
        }
        if (generate) generate.disabled = !available;
    };
    const refreshOffer = async () => {
        if (!generationOffer) return null;
        const quote = await jsonMutation({mutation:'generation_quote'});
        displayOffer(quote);
        return quote;
    };
    topupDialog?.querySelector('[data-player-topup-close]')?.addEventListener('click', () => topupDialog.close());
    topupDialog?.addEventListener('click', event => {
        if (event.target === topupDialog) topupDialog.close();
    });
    generationOffer?.querySelector('[data-player-topup-open]')?.addEventListener('click', () => {
        if (topupDialog?.showModal && !topupDialog.open) topupDialog.showModal();
    });
    if (generationOffer) {
        void refreshOffer().catch(() => {
            if (generate) generate.disabled = true;
            canGenerate = false;
            if (generationFields) generationFields.hidden = true;
            if (offerUnavailable) {
                offerUnavailable.hidden = false;
                offerUnavailable.textContent = 'Не удалось проверить стоимость и баланс. Обнови страницу.';
            }
        });
    }
    generate?.addEventListener('click', async () => {
        generate.disabled = true;
        try {
            message('Проверяем стоимость и баланс...');
            const quote = await refreshOffer();
            if (!quote || !quote.can_generate || quote.price_minor === null) {
                message('Недостаточно средств или стоимость генерации временно недоступна.', true);
                return;
            }
            if (!window.confirm('Создать реалистичный аватар за ' + money(quote.price_minor) +
                '? Сначала будут списаны бонусные средства, затем реальные.')) {
                message('Генерация не запускалась.');
                return;
            }

            const payload = new FormData(form);
            payload.set('_method','PATCH');
            payload.set('mutation','generate_2d');
            const team = workspace.querySelector('[data-player-generation-team]');
            if (team?.value) payload.set('generation_team_id',team.value);
            if (workspace.querySelector('[data-player-generation-logo]')?.checked) {
                payload.set('generation_with_team_logo','1');
            }
            for (const input of workspace.querySelectorAll('[data-player-face-slot]')) {
                if (input.files?.[0]) {
                    payload.set('generation_face_references[' + input.dataset.playerFaceSlot + ']',input.files[0]);
                }
            }
            message('Загружаем фотографии и запускаем генерацию...');
            const result = await request(payload);
            if (result.status === 'pending' && result.status_url) {
                message('Генерация запущена. Проверяем результат...');
                await refreshOffer().catch(() => {});
                await pollGeneration(result.status_url,result.generation_id);
            } else if (result.image_data_url) {
                // A successful synchronous response contains the already validated image.
                if (result.image_data_url.startsWith('data:image/')) {
                    figure.src = result.image_data_url;
                    figure.classList.add('is-generated');

                    message('Аватар сгенерирован.');
                }
            } else {
                message(result.message || 'Генерация запущена.');
            }
        } catch (error) {
            message(error.message || 'Не удалось создать аватар.', true);
        } finally {
            generate.disabled = !canGenerate;
            await refreshOffer().catch(() => {});
        }
    });
    void showExisting();
}
