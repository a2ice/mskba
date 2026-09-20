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

Заполняется после реализации.
