{{-- Shared game planning editor. Caller provides persistence and team search adapters. --}}
@php
    $editorId = $editorId ?? 'event-game-editor';
    $editorApp = $editorApp ?? true;
    $editorPrimary = $editorApp ? 'button primary' : 'btn btn--primary';
    $editorSecondary = $editorApp ? 'button secondary' : 'btn btn--secondary';
@endphp
<dialog class="mskba-modal event-game-editor {{ $editorApp ? 'event-game-editor--app' : 'event-game-editor--legacy' }}" id="{{ $editorId }}" data-game-editor aria-labelledby="{{ $editorId }}-title">
    <header class="mskba-modal__header event-game-editor__header">
        <h2 id="{{ $editorId }}-title" data-game-editor-title>Создать игру</h2>
        <button type="button" class="icon-button" data-game-editor-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </header>
    <form data-game-editor-form novalidate>
        <div class="mskba-modal__body mskba-scroll event-game-editor__body">
            <label class="event-game-editor__field">
                <span>Название игры</span>
                <input type="text" name="title" maxlength="150" required aria-describedby="{{ $editorId }}-error-title">
                <small class="event-game-editor__error" id="{{ $editorId }}-error-title" data-game-error="title" role="alert" hidden></small>
            </label>
            <label class="event-game-editor__field">
                <span>Формат игры</span>
                <span class="select-control"><select name="format">
                    <option value="">Не определен</option>
                    <option value="streetball_1x1">1×1</option>
                    <option value="streetball_3x3">3×3</option>
                    <option value="basketball_5x5">5×5</option>
                </select><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#chevron-down"/></svg></span>
            </label>
            <label class="event-game-editor__field" data-game-type-row hidden>
                <span>Тип игры</span>
                <span class="select-control"><select name="recruitment_mode">
                    <option value="individual_draft">Из участников</option>
                    <option value="preformed_teams">Между командами</option>
                </select><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#chevron-down"/></svg></span>
            </label>
            <div class="event-game-editor__teams" data-game-teams hidden>
                @foreach(['a' => 1, 'b' => 2] as $side => $number)
                    <div class="event-game-editor__field" data-game-team="{{ $side }}">
                        <label for="{{ $editorId }}-team-{{ $side }}">Команда {{ $number }}</label>
                        <div class="predictive-search__input-wrap event-game-editor__predictive">
                            <input id="{{ $editorId }}-team-{{ $side }}" type="search" autocomplete="off" class="predictive-search__input"
                                placeholder="Начните вводить название" role="combobox" aria-autocomplete="list" aria-expanded="false"
                                aria-controls="{{ $editorId }}-results-{{ $side }}" aria-describedby="{{ $editorId }}-error-{{ $side }}">
                            <div class="predictive-search__list event-game-editor__results" id="{{ $editorId }}-results-{{ $side }}" role="listbox" hidden></div>
                        </div>
                        <small class="event-game-editor__error" id="{{ $editorId }}-error-{{ $side }}" data-game-error="team_{{ $side }}_id" role="alert" hidden></small>
                    </div>
                @endforeach
                <p class="event-game-editor__hint" data-game-invite-hint hidden>Вы можете отправить приглашения командам после завершения создания данного мероприятия.</p>
            </div>
            <details class="event-game-editor__advanced" data-game-advanced hidden>
                <summary>Дополнительные настройки</summary>
                <div class="event-game-editor__advanced-fields">
                    <label class="event-game-editor__field"><span>Система подсчёта очков</span>
                        <span class="select-control"><select name="scoring_type">
                            <option value="streetball">Стритбол — 1 и 2 очка</option>
                            <option value="basketball">Баскетбол — 1, 2 и 3 очка</option>
                        </select><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#chevron-down"/></svg></span>
                    </label>
                    <label class="event-game-editor__field"><span>Деление игры на периоды</span>
                        <span class="select-control"><select name="periods">
                            <option value="whole_game">Игра целиком</option>
                            <option value="2">Две половины</option>
                            <option value="4">Четыре четверти</option>
                        </select><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#chevron-down"/></svg></span>
                    </label>
                    <p class="event-game-editor__hint">Настройки используются при ведении счёта, фиксации игровых действий и формировании статистики.</p>
                </div>
            </details>
            <p class="event-game-editor__error" data-game-submit-error role="alert" hidden></p>
        </div>
        <footer class="mskba-modal__footer event-game-editor__footer">
            <button type="button" class="{{ $editorSecondary }}" data-game-editor-close>Отмена</button>
            <button type="submit" class="{{ $editorPrimary }}">Сохранить</button>
        </footer>
    </form>
</dialog>
@once
<style>
.event-game-editor[hidden],.event-game-editor [hidden]{display:none!important}
.event-game-editor__header{display:flex;align-items:center;gap:12px;padding:20px 22px;border-bottom:1px solid var(--line,#606772)}
.event-game-editor__header h2{flex:1;margin:0;font-size:clamp(20px,2vw,26px);line-height:1.3}
.event-game-editor__header button{width:44px;height:44px;display:grid;place-items:center;flex:none}
.event-game-editor__header svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
.event-game-editor>form{display:flex;flex-direction:column;min-height:0;gap:0;overflow:hidden}
.event-game-editor__body,.event-game-editor__advanced-fields,.event-game-editor__teams{display:grid;gap:16px;align-content:start}
.event-game-editor__body{padding:20px 22px}
.event-game-editor__field{display:grid;gap:8px;min-width:0;font-size:14px;font-weight:600}
.event-game-editor__field :is(input,select){box-sizing:border-box;width:100%;min-width:0;min-height:48px;border:1px solid var(--control-line,#707a86);border-radius:12px;padding:11px 12px;background:var(--field,#292e35);color:var(--text,#f5f6f8);font-size:16px;line-height:24px;font-weight:400}
.event-game-editor__field .select-control{position:relative;display:block;width:100%;min-width:0}
.event-game-editor--app .select-control>select{appearance:none;-webkit-appearance:none;padding-inline-end:48px;cursor:pointer}
.event-game-editor--app .select-control>svg{display:block;position:absolute;inset-inline-end:16px;top:50%;transform:translateY(-50%);width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;pointer-events:none;color:var(--muted,#adb3bb)}
.event-game-editor--legacy .select-control>select{appearance:auto;-webkit-appearance:auto}
.event-game-editor--legacy .select-control>svg{display:none}
@media(forced-colors:active){.event-game-editor--app .select-control>select{appearance:auto;-webkit-appearance:auto}.event-game-editor--app .select-control>svg{display:none}}
.event-game-editor__field [aria-invalid=true]{border-color:var(--danger,#d23e45)}
.event-game-editor__error{margin:0;color:var(--danger,#d23e45);font-size:14px;font-weight:400}
.event-game-editor__hint{margin:0;color:var(--muted,#929aa4);font-size:14px;line-height:20px;font-weight:400}
.event-game-editor__advanced{border-top:1px solid var(--line,#606772);padding-top:12px}
.event-game-editor__advanced summary{cursor:pointer;font-weight:600}
.event-game-editor__advanced-fields{padding-top:16px}
.event-game-editor__predictive{position:relative;min-width:0}
.event-game-editor__results{position:relative;max-height:180px;overflow:auto;display:grid;gap:2px;background:var(--surface,#292e35);border:1px solid var(--line,#606772);border-radius:12px;margin-top:4px}
.event-game-editor__option{display:flex;align-items:center;gap:10px;width:100%;text-align:left;background:none;color:inherit;border:0;padding:8px 10px;cursor:pointer;font-size:14px}
.event-game-editor__option:is(:focus-visible,[aria-selected=true],:hover){background:var(--surface-raised,#363b43)}
.event-game-editor__option img,.event-game-editor__avatar{width:32px;height:32px;border-radius:50%;object-fit:contain;flex:none}
.event-game-editor__avatar{display:grid;place-items:center;background:var(--surface-raised,#363b43)}
.event-game-editor__footer{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px;border-top:1px solid var(--line,#606772)}
.event-game-editor--legacy:not([open]){display:none}
.event-game-editor--legacy[open]{display:flex;flex-direction:column;width:min(560px,calc(100vw - 24px));max-height:90dvh;padding:0;overflow:hidden}
.event-game-editor--legacy::backdrop{background:rgba(0,0,0,.65)}
</style>
@endonce
