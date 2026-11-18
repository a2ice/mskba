<x-filament-widgets::widget>
    <div class="mskba-bo-dashboard-grid">
        @foreach ($groups as $group)
            <x-filament::section>
                <x-slot name="heading">{{ $group->getLabel() }}</x-slot>

                <ul class="mskba-bo-dashboard-links">
                    @foreach ($group->getItems() as $item)
                        @if ($item->getUrl())
                            <li>
                                <a class="mskba-bo-dashboard-link" href="{{ $item->getUrl() }}"
                                    @if ($item->shouldOpenUrlInNewTab()) target="_blank" rel="noopener noreferrer" @endif>
                                    <span>{{ $item->getLabel() }}</span>
                                    <span aria-hidden="true" class="mskba-bo-dashboard-arrow">›</span>
                                </a>
                            </li>
                        @endif
                        @foreach ($item->getChildItems() as $child)
                            @if ($child->getUrl())
                                <li>
                                    <a class="mskba-bo-dashboard-link" href="{{ $child->getUrl() }}"
                                        @if ($child->shouldOpenUrlInNewTab()) target="_blank" rel="noopener noreferrer" @endif>
                                        <span>{{ $child->getLabel() }}</span>
                                        <span aria-hidden="true" class="mskba-bo-dashboard-arrow">›</span>
                                    </a>
                                </li>
                            @endif
                        @endforeach
                    @endforeach
                </ul>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
