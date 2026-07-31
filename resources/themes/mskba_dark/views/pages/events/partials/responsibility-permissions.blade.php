@php
    $selectedValues = collect($selected ?? [])->map(fn ($permission) => $permission instanceof \BackedEnum ? $permission->value : (string) $permission);
    $allowedValues = collect($allowed ?? [])->map(fn ($permission) => $permission instanceof \BackedEnum ? $permission->value : (string) $permission);
@endphp

<div class="event-responsibility-permissions" data-responsibility-permissions>
    <input type="hidden" name="permissions_present" value="1">
    @foreach($responsibilityPermissionGroups as $group => $permissions)
        @php
            $available = collect($permissions)->filter(fn ($permission) => $allowedValues->contains($permission->value));
            $allSelected = $available->isNotEmpty() && $available->every(fn ($permission) => $selectedValues->contains($permission->value));
        @endphp
        @if($available->isNotEmpty())
            <fieldset class="event-responsibility-permissions__group" data-permission-group="{{ $group }}">
                <legend>{{ $group === 'event' ? 'Мероприятие' : 'Мини-игры' }}</legend>
                <label class="form-toggle event-responsibility-permissions__all">
                    <input class="form-toggle__input" type="checkbox" @checked($allSelected) data-permission-group-toggle>
                    <span class="form-toggle__control" aria-hidden="true"></span>
                    <strong class="form-toggle__title">Полное управление</strong>
                </label>
                <div class="event-responsibility-permissions__options">
                    @foreach($available as $permission)
                        @include('theme::partials.forms.toggle', [
                            'id' => 'responsibility-'.$participant->id.'-'.$permission->value.'-'.($formContext ?? 'invite'),
                            'name' => 'permissions[]',
                            'value' => $permission->value,
                            'title' => $permission->label(),
                            'checked' => $selectedValues->contains($permission->value),
                            'includeHiddenInput' => false,
                            'wrapperClass' => 'event-responsibility-permissions__option',
                            'inputAttributes' => ['data-permission-option' => true],
                        ])
                    @endforeach
                </div>
            </fieldset>
        @endif
    @endforeach
</div>
