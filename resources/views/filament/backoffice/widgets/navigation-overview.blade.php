<x-filament-widgets::widget>
    <div class="mskba-bo-dashboard-grid">
        @foreach ($groups as $group)
            <div class="mskba-bo-dashboard-card">
                <x-filament::section>
                    <x-slot name="heading">
                        <span class="mskba-bo-dashboard-heading">
                            <span class="mskba-bo-dashboard-icon" aria-hidden="true">
                                {{ \Filament\Support\generate_icon_html($group['icon']) }}
                            </span>
                            <span class="mskba-bo-dashboard-heading-label">{{ $group['label'] }}</span>
                            <span class="mskba-bo-dashboard-count" aria-label="Переходов: {{ $group['count'] }}">
                                {{ $group['count'] }}
                            </span>
                        </span>
                    </x-slot>

                    <ul class="mskba-bo-dashboard-links">
                        @foreach ($group['items'] as $item)
                            <li>
                                <a class="mskba-bo-dashboard-link" href="{{ $item->getUrl() }}"
                                    @if ($item->shouldOpenUrlInNewTab()) target="_blank" rel="noopener noreferrer" @endif>
                                    <span>{{ $item->getLabel() }}</span>
                                    <span aria-hidden="true" class="mskba-bo-dashboard-arrow">›</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    @if (count($group['tools']) > 0)
                        <div class="mskba-bo-dashboard-subsection">
                            <h3 class="mskba-bo-dashboard-subheading">Интеграции и API</h3>
                            <ul class="mskba-bo-dashboard-links">
                                @foreach ($group['tools'] as $tool)
                                    <li>
                                        <a class="mskba-bo-dashboard-link" href="{{ $tool->getUrl() }}"
                                            @if ($tool->shouldOpenUrlInNewTab()) target="_blank" rel="noopener noreferrer" @endif>
                                            <span>{{ $tool->getLabel() }}</span>
                                            <span aria-hidden="true" class="mskba-bo-dashboard-arrow">›</span>
                                        </a>
                                        @if ($tool->getKey() === 'backoffice.venue-integrations')
                                            <p class="mskba-bo-dashboard-tool-hint">Выберите площадку → вкладка «Интеграции»</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </x-filament::section>
            </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
