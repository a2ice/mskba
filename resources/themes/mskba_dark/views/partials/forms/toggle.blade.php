@php
    $id = $id ?? 'toggle-'.str_replace(['[', ']'], '-', $name);
    $checked = (bool) ($checked ?? false);
    $description = $description ?? null;
    $wrapperClass = $wrapperClass ?? 'form-group field mb-3';
    $inputAttributes = $inputAttributes ?? [];
@endphp

<div class="{{ $wrapperClass }}">
    <input type="hidden" name="{{ $name }}" value="0">
    <label class="form-toggle" for="{{ $id }}">
        <input
            id="{{ $id }}"
            class="form-toggle__input"
            type="checkbox"
            name="{{ $name }}"
            value="1"
            @checked($checked)
            @foreach($inputAttributes as $attribute => $attributeValue)
                @if($attributeValue === true)
                    {{ $attribute }}
                @elseif($attributeValue !== false && $attributeValue !== null)
                    {{ $attribute }}="{{ $attributeValue }}"
                @endif
            @endforeach
        >
        <span class="form-toggle__control" aria-hidden="true"></span>
        <strong class="form-toggle__title">{{ $title }}</strong>
        @if($description)
            <small class="form-toggle__description">{{ $description }}</small>
        @endif
    </label>
    @error($name) <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
</div>
