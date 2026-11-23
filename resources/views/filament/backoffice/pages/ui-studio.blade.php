<x-filament-panels::page>
    <div id="mskba-studio-workspace" data-studio-origin="{{ $studioOrigin }}" data-ticket-url="{{ url('/backoffice/ui-studio/ticket') }}">
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <button type="button" data-studio-tab="studio" aria-pressed="true" class="fi-btn fi-btn-color-primary">Интерфейсы</button>
            <button type="button" data-studio-tab="kit" aria-pressed="false" class="fi-btn fi-btn-color-gray">UI Kit / Гайдлайн</button>
            <a href="{{ $studioOrigin }}/uikit" target="_blank" rel="noopener noreferrer" class="text-sm underline">Отдельный просмотр Studio</a>
        </div>
        <p class="text-sm text-gray-500 mb-3" data-studio-status aria-live="polite">
            @if($bridgeEnabled)Подключение к изолированной песочнице…@else Интеграция UI Studio не настроена. UI Kit доступен ниже. @endif
        </p>
        <section data-studio-panel="studio">
            @if($bridgeEnabled)
                <iframe data-studio-iframe src="{{ $studioUrl }}" title="MSKBA UI Studio" loading="eager" allow="clipboard-write" referrerpolicy="no-referrer" style="width:100%;height:75vh;min-height:560px;border:1px solid #a3a3a34a;border-radius:12px"></iframe>
            @else
                <p>Для подключения необходимо настроить отдельный UI Studio runtime и одноразовые билеты доступа.</p>
            @endif
        </section>
        <section data-studio-panel="kit" hidden>
            @if($kitUrl)
                <iframe src="{{ $kitUrl }}" title="Действующий MSKBA UI Kit" loading="lazy" style="width:100%;height:75vh;min-height:560px;border:1px solid #a3a3a34a;border-radius:12px"></iframe>
            @else
                <p>Сначала создайте тему оформления в Filament.</p>
            @endif
        </section>
    </div>
    <script>
        (() => {
            const root = document.getElementById('mskba-studio-workspace');
            if (!root || root.dataset.initialized) return;
            root.dataset.initialized = '1';
            const origin = root.dataset.studioOrigin;
            const frame = root.querySelector('[data-studio-iframe]');
            const status = root.querySelector('[data-studio-status]');
            root.querySelectorAll('[data-studio-tab]').forEach(button => button.addEventListener('click', () => {
                const target = button.dataset.studioTab;
                root.querySelectorAll('[data-studio-tab]').forEach(tab => tab.setAttribute('aria-pressed', String(tab.dataset.studioTab === target)));
                root.querySelectorAll('[data-studio-panel]').forEach(panel => { panel.hidden = panel.dataset.studioPanel !== target; });
            }));
            if (!frame) return;
            window.addEventListener('message', async event => {
                if (event.origin !== origin || event.source !== frame.contentWindow) return;
                if (!event.data || event.data.type !== 'mskba-ui-studio:ready') {
                    if (event.data?.type === 'mskba-ui-studio:authorized') status.textContent = 'UI Studio подключена';
                    if (event.data?.type === 'mskba-ui-studio:error') status.textContent = 'Не удалось открыть Studio: ' + String(event.data.message || 'проверьте настройки');
                    return;
                }
                try {
                    const response = await fetch(root.dataset.ticketUrl, {
                        method: 'POST', credentials: 'same-origin',
                        headers: {'Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())},
                    });
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    const data = await response.json();
                    frame.contentWindow.postMessage({type:'mskba-ui-studio:ticket',ticket:data.ticket},origin);
                } catch (error) {
                    status.textContent = 'Не удалось подтвердить доступ к Studio: ' + error.message;
                }
            });
        })();
    </script>
</x-filament-panels::page>
