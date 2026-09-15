<div class="mb-3">
    <label for="ownershipEvidence" class="form-label">Подтверждение полномочий</label>
    <textarea id="ownershipEvidence" name="ownership_evidence" class="form-control @error('ownership_evidence') is-invalid @enderror" rows="3" maxlength="5000">{{ old('ownership_evidence', $claim?->evidence ?? '') }}</textarea>
    <small class="form-text">Опишите своё отношение к площадке. Для отправки нужно не менее 20 символов; черновик можно сохранить без текста.</small>
    @error('ownership_evidence') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>
<div class="mb-3">
    <label for="ownershipDocuments" class="form-label">Сканы документов</label>
    <input id="ownershipDocuments" type="file" name="ownership_documents[]" multiple accept="image/jpeg,image/png,application/pdf" class="form-control @error('ownership_documents') is-invalid @enderror">
    <small class="form-text">Необязательно: можно добавить позже. JPG, PNG или PDF, до 10 МБ на файл; до 10 документов и 50 МБ в заявке. Документы не публикуются на странице площадки. После ошибки загрузки выберите файлы заново.</small>
    @foreach($errors->get('ownership_documents') as $message) <div class="text-danger">{{ $message }}</div> @endforeach
    @foreach($errors->get('ownership_documents.*') as $messages)
        @foreach($messages as $message) <div class="text-danger">{{ $message }}</div> @endforeach
    @endforeach
</div>
