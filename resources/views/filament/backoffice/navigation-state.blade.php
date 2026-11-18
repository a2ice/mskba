@php
    // Filament persists disclosure state globally in localStorage, so
    // ->collapsed() alone does not reopen the active group on navigation.
    // Calculate the initial state before its sidebar/store initialize.
    $collapsedGroups = collect(\Filament\Facades\Filament::getNavigation())
        ->filter(fn (\Filament\Navigation\NavigationGroup $group): bool => filled($group->getLabel()) && ! $group->isActive())
        ->map(fn (\Filament\Navigation\NavigationGroup $group): string => (string) $group->getLabel())
        ->values()
        ->all();
@endphp
<script>
(function () {
    const storageKey = 'filament:sidebar:collapsed_groups';
    const initiallyCollapsed = @js($collapsedGroups);

    try {
        localStorage.setItem(storageKey, JSON.stringify(initiallyCollapsed));
    } catch (error) {
        // In restricted storage mode the native Alpine disclosure still works.
    }

    function syncGroupState() {
        const store = window.Alpine?.store('sidebar');
        const sidebar = document.querySelector('#fi-main-sidebar .fi-sidebar-nav');

        if (!store || !sidebar) return;

        const collapsed = Array.from(sidebar.querySelectorAll('.fi-sidebar-group[data-group-label]'))
            .filter(group => !group.classList.contains('fi-active'))
            .map(group => group.dataset.groupLabel)
            .filter(Boolean);

        if (JSON.stringify(store.collapsedGroups ?? []) !== JSON.stringify(collapsed)) {
            store.collapsedGroups = collapsed;
        }
    }

    // User-triggered toggles remain intact on the current page; the next page
    // starts with only its active group open (dashboard: all groups collapsed).
    document.addEventListener('livewire:navigated', syncGroupState);
    document.addEventListener('alpine:initialized', syncGroupState);
})();
</script>
