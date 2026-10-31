@php
    $current = $this->getRecord();
    $model = $current instanceof \App\Modules\Identity\Domain\Models\User
        ? $current->profile
        : $current;
@endphp
@if($model && method_exists($model, 'banner'))
    <div wire:ignore>
        @include('components.entity-banner-editor', ['model' => $model, 'context' => 'backoffice'])
    </div>
@else
    <p class="text-sm text-gray-500">Для загрузки баннера сначала сохраните объект и заполните профиль пользователя.</p>
@endif
