# План Task 165

- [x] Создать отдельную ветку от актуального `main` и не затрагивать параллельные изменения.
- [x] Заменить public court `<select>` на компактный badge/count + общий modal picker.
- [x] Перенести badge выбора зала поверх hero-фотографии после типа площадки.
- [x] Свести operational status в overlay к цветному bullet с title-style tooltip.
- [x] Обновить карточку «Количество залов» и использовать существующую `ti-layout-grid`.
- [x] Добавить `Media` relation и стабильный morph alias для `VenueCourt`.
- [x] Добавить upload/activate/delete галереи конкретного зала с WebP normalization и лимитом 3.
- [x] Добавить редактор фотографий для каждого зала в account UI.
- [x] Переключить публичную hero/gallery на court photos с fallback к Venue gallery.
- [x] Добавить regression tests для picker UI, fallback/override и прав управления фото.
- [ ] Запустить полный PR CI и исправить найденные регрессии.
- [ ] Перед merge повторно сверить ветку с актуальным `main` и параллельной работой.
- [ ] Влить PR в `main` только после зелёного CI.
- [ ] Дождаться зелёного production deploy и выполнить smoke-check.
- [ ] Отметить Task 165 завершённой в документации после успешного production deploy.
