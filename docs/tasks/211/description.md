# 211 - Исправить Telegram sticky-header и упростить venue acquisition entry

## Оригинальное описание

На мобильной главной Telegram Mini App контент первого экрана визуально прижат к шапке. После перехода sticky-header в fixed-состояние логотип и аватар попадают под верхние контролы Telegram, потому что Telegram safe-area остаётся на внешнем header, а фиксируется внутренний wrapper.

На campaign onboarding `/join/{code}` после перевода header в document flow остался старый большой верхний отступ. Отдельный блок «Этот вход связан с площадкой / Подтвердить, что я здесь» перегружает первый экран: геопроверка нужна для attribution, а не как отдельная пользовательская задача. Её следует запускать из основных CTA onboarding.

## Подробное описание

### Telegram header

- перенести Telegram top safe-area с `.site-header` на `.header-wrapper`, который остаётся одним и тем же элементом до и после перехода в fixed-состояние;
- sticky-header должен сохранять одинаковую вертикальную геометрию в normal/fixed state;
- добавить небольшой визуальный зазор между in-flow header и первым экраном главной Telegram Mini App;
- не менять обычный mobile web.

### Acquisition onboarding

- убрать устаревший большой top-padding, который раньше компенсировал постоянно fixed header;
- убрать отдельную venue-context карточку из первого экрана;
- сохранить campaign/venue attribution и добровольную геопроверку;
- если геопроверка включена, запрос запускать по пользовательскому жесту при нажатии основных CTA «Присоединиться» и «У меня уже есть аккаунт»;
- если permission уже был выдан, как и раньше разрешено выполнить проверку автоматически;
- отказ, timeout или недоступная геолокация не блокируют onboarding и не меняют выбранный flow;
- raw координаты по-прежнему не сохраняются.

## Проверка

- frontend production build;
- backend test suite;
- Telegram header: initial и fixed state не попадают под Telegram controls;
- главная Mini App имеет небольшой gap после header;
- onboarding mobile больше не содержит большой пустой зоны;
- standalone venue-location card отсутствует;
- CTA onboarding сохраняют обычное переключение wizard и параллельно инициируют location verification только для кампаний с включённой проверкой.

## Результат

Telegram top safe-area перенесён с внешнего `.site-header` на `.header-wrapper`. Поэтому один и тот же wrapper сохраняет safe padding как в обычном document-flow состоянии, так и после перехода в `position: fixed`; логотип и профиль больше не должны уезжать под Telegram controls. На главной Mini App между in-flow header и hero добавлен небольшой отдельный gap.

У acquisition onboarding удалена standalone venue/location карточка и старые 88–112 px компенсации под permanently-fixed header. Геопроверка кампании сохранилась: при уже выданном permission она может отработать автоматически, иначе запускается непосредственно пользовательским нажатием «Присоединиться» или «У меня уже есть аккаунт». Результат проверки не блокирует смену шага wizard.

Добавлен feature-test, фиксирующий, что venue-backed campaign сохраняет location target в markup, но больше не выводит отдельные тексты «Этот вход связан с площадкой» / «Подтвердить, что я здесь».
