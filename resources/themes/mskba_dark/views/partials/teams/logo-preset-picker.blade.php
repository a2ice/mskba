<fieldset class="section-card mb-4 team-logo-presets">
    <legend class="form-label">Логотип команды</legend>
    <p class="form-hint">Выберите готовую эмблему. После создания команды её можно заменить своим логотипом.</p>
    <div class="team-logo-presets__grid">
        <label class="team-logo-presets__option">
            <input type="radio" name="logo_preset" value="" @checked(old('logo_preset', '') === '')>
            <span class="team-logo-presets__surface"><i class="ti ti-photo-off" aria-hidden="true"></i><span>Без логотипа</span></span>
        </label>
        @foreach($presets as $id => $url)
            <label class="team-logo-presets__option">
                <input type="radio" name="logo_preset" value="{{ $id }}" aria-label="Эмблема {{ $loop->iteration }}" @checked(old('logo_preset') === $id)>
                <span class="team-logo-presets__surface"><img src="{{ $url }}" alt="" width="80" height="80" loading="lazy"></span>
            </label>
        @endforeach
    </div>
    @error('logo_preset')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</fieldset>
