@php $title = 'SEO страницы'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => $entityType->label().' · '.$entityTitle,
])

@section('section-heading-action')
    <a class="btn btn--secondary btn--sm" href="{{ route('admin.content.seo', ['entity_type' => $entityType->value]) }}">К списку</a>
@endsection

@section('section-content')
    @if(session('status')) <div class="alert alert-success mb-3">{{ session('status') }}</div> @endif

    <form method="POST" action="{{ route('admin.content.seo.update', [$entityType->value, $entity->id]) }}">
        @csrf
        @method('PUT')

        <div class="content-admin-form-section">
            <div class="form-group">
                <label class="form-label" for="pageSeoTitle">Meta title</label>
                <input id="pageSeoTitle" class="form-control" name="meta_title" maxlength="255" value="{{ old('meta_title', $setting->meta_title) }}">
                <div class="form-help">Если оставить пустым, используется название страницы.</div>
                @error('meta_title') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="pageSeoDescription">Meta description</label>
                <textarea id="pageSeoDescription" class="form-control" name="meta_description" rows="4" maxlength="320">{{ old('meta_description', $setting->meta_description) }}</textarea>
                <div class="form-help">Краткое описание страницы для поисковой выдачи и превью ссылок.</div>
                @error('meta_description') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="pageSeoKeywords">Meta keywords</label>
                <input id="pageSeoKeywords" class="form-control" name="meta_keywords" maxlength="2000" value="{{ old('meta_keywords', $setting->meta_keywords) }}" placeholder="баскетбол, площадка, Москва">
                <div class="form-help">Через запятую. Поле поддерживается, хотя современные поисковики почти не используют его при ранжировании.</div>
                @error('meta_keywords') <div class="form-error">{{ $message }}</div> @enderror
            </div>
        </div>

        <button class="btn btn--primary" type="submit">Сохранить</button>
    </form>
@endsection
