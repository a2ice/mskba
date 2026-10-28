const workspace = document.querySelector('[data-player-workspace]');
if (workspace) {
    const preview = workspace.querySelector('[data-player-preview]');
    const form = workspace.querySelector('[data-player-physical-form]');
    const height = form?.querySelector('[data-physical-height]');
    const weight = form?.querySelector('[data-physical-weight]');
    const body = form?.querySelector('[data-physical-body-type]');
    const adiposity = form?.querySelector('[data-physical-adiposity]');
    const stage = workspace.querySelector('[data-player-stage]');
    const figure = workspace.querySelector('[data-player-figure]');

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
        const bodyAdjustment = {slim:-0.09, athletic:0, muscular:0.12, stocky:0.15, large:0.20}[body?.value] || 0;
        const fatAdjustment = {low:-0.09, average:0, elevated:0.12, pronounced:0.23}[adiposity?.value] || 0;
        const width = Math.min(1.4, Math.max(0.78, 1 + (bmi-23)*0.009 + bodyAdjustment + fatAdjustment));
        stage.style.setProperty('--figure-height', (Math.min(220, Math.max(150, cm)) / 250 * 100) + '%');
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
    [height, weight, body, adiposity].forEach(input => input?.addEventListener('input', onPhysicalInput));
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
    async function showExisting() {
        if (!form) return;
        try {
            const history = await jsonMutation({mutation:'generation_history'});
            const image = history.generations?.find(item => item.is_primary && item.image_url);
            if (image?.image_url) setImage(image.image_url);
            const pending = history.generations?.find(item => ['pending','processing'].includes(item.status));
            if (pending) message('Генерация ранее запущена. Обнови страницу позже, чтобы увидеть результат.');
        } catch { /* A missing generation must not block profile editing. */ }
    }

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
                message('Аватар готов и установлен на шкале роста.');
                return;
            }
        }
        message('Генерация ещё продолжается. Результат появится после обновления страницы.');
    }

    const generate = workspace.querySelector('[data-player-generate]');
    generate?.addEventListener('click', async () => {
        generate.disabled = true;
        try {
            message('Проверяем стоимость...');
            const quote = await jsonMutation({mutation:'generation_quote'});
            const rubles = (Number(quote.price_minor || 0) / 100).toLocaleString('ru-RU', {
                style:'currency', currency:'RUB',
            });
            if (!window.confirm('Создать реалистичный аватар за ' + rubles +
                '? Для этого используются прикреплённые фотографии лица.')) {
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
            generate.disabled = false;
        }
    });
    void showExisting();
}
