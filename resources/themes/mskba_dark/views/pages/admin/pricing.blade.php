@php
    $title = 'Прайс';
    $priceKey = static fn (int $serviceId, ?int $variantId = null): string => $serviceId.':'.($variantId ?? 'base');
@endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Категории, услуги, варианты и версионные цены. Изменение цены не переписывает историю.',
])

@section('section-content')
    <div class="admin-settings-grid">
        <section class="admin-acquisition-card">
            <div>
                <h2>Новая категория</h2>
                <p class="admin-muted">Код стабилен и используется бизнес-логикой. Название и описание можно менять.</p>
            </div>

            <form method="POST" action="{{ route('admin.pricing.categories.store') }}" class="admin-acquisition-grid">
                @csrf
                <label class="admin-acquisition-field">
                    <span class="form-label">Код</span>
                    <input class="form-control" type="text" name="code" placeholder="ai_generation" required>
                </label>
                <label class="admin-acquisition-field">
                    <span class="form-label">Название</span>
                    <input class="form-control" type="text" name="name" required>
                </label>
                <label class="admin-acquisition-field admin-acquisition-grid__wide">
                    <span class="form-label">Описание</span>
                    <textarea class="form-control" name="description" rows="2"></textarea>
                </label>
                <label class="admin-acquisition-field">
                    <span class="form-label">Порядок</span>
                    <input class="form-control" type="number" name="sort_order" min="0" value="0" required>
                </label>
                <label class="form-toggle">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" checked>
                    <span>Активна</span>
                </label>
                <div class="admin-acquisition-save admin-acquisition-grid__wide">
                    <button class="btn btn--primary" type="submit">Добавить категорию</button>
                </div>
            </form>
        </section>

        @forelse($categories as $category)
            <section class="admin-acquisition-card">
                <div class="admin-acquisition-card__header">
                    <div>
                        <h2>{{ $category->name }}</h2>
                        <p class="admin-muted"><code>{{ $category->code }}</code> · {{ $category->is_active ? 'активна' : 'отключена' }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.pricing.categories.destroy', $category) }}" onsubmit="return confirm('Удалить категорию и все её услуги из активного каталога?')">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn--danger btn--sm" type="submit">Удалить</button>
                    </form>
                </div>

                <form method="POST" action="{{ route('admin.pricing.categories.update', $category) }}" class="admin-acquisition-grid">
                    @csrf
                    @method('PUT')
                    <label class="admin-acquisition-field">
                        <span class="form-label">Код</span>
                        <input class="form-control" type="text" name="code" value="{{ $category->code }}" required>
                    </label>
                    <label class="admin-acquisition-field">
                        <span class="form-label">Название</span>
                        <input class="form-control" type="text" name="name" value="{{ $category->name }}" required>
                    </label>
                    <label class="admin-acquisition-field admin-acquisition-grid__wide">
                        <span class="form-label">Описание</span>
                        <textarea class="form-control" name="description" rows="2">{{ $category->description }}</textarea>
                    </label>
                    <label class="admin-acquisition-field">
                        <span class="form-label">Порядок</span>
                        <input class="form-control" type="number" name="sort_order" min="0" value="{{ $category->sort_order }}" required>
                    </label>
                    <label class="form-toggle">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked($category->is_active)>
                        <span>Активна</span>
                    </label>
                    <div class="admin-acquisition-save admin-acquisition-grid__wide">
                        <button class="btn btn--secondary" type="submit">Сохранить категорию</button>
                    </div>
                </form>

                <hr>

                <div>
                    <h3>Добавить услугу</h3>
                    <form method="POST" action="{{ route('admin.pricing.services.store') }}" class="admin-acquisition-grid">
                        @csrf
                        <input type="hidden" name="category_id" value="{{ $category->id }}">
                        <label class="admin-acquisition-field">
                            <span class="form-label">Код</span>
                            <input class="form-control" type="text" name="code" placeholder="service_code" required>
                        </label>
                        <label class="admin-acquisition-field">
                            <span class="form-label">Название</span>
                            <input class="form-control" type="text" name="name" required>
                        </label>
                        <label class="admin-acquisition-field admin-acquisition-grid__wide">
                            <span class="form-label">Описание</span>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </label>
                        <label class="admin-acquisition-field">
                            <span class="form-label">Порядок</span>
                            <input class="form-control" type="number" name="sort_order" min="0" value="0" required>
                        </label>
                        <label class="form-toggle">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" checked>
                            <span>Активна</span>
                        </label>
                        <div class="admin-acquisition-save admin-acquisition-grid__wide">
                            <button class="btn btn--primary btn--sm" type="submit">Добавить услугу</button>
                        </div>
                    </form>
                </div>

                @foreach($category->services as $service)
                    @php($basePrice = $currentPrices->get($priceKey($service->id)))
                    <section class="admin-settings-group">
                        <div class="admin-acquisition-card__header">
                            <div>
                                <h3>{{ $service->name }}</h3>
                                <p class="admin-muted">
                                    <code>{{ $service->code }}</code> · {{ $service->is_active ? 'активна' : 'отключена' }}
                                    · базовая цена: {{ $basePrice?->formattedAmount() ?? 'не задана' }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('admin.pricing.services.destroy', $service) }}" onsubmit="return confirm('Удалить услугу, её варианты и цены из активного каталога?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn--danger btn--sm" type="submit">Удалить</button>
                            </form>
                        </div>

                        <form method="POST" action="{{ route('admin.pricing.services.update', $service) }}" class="admin-acquisition-grid">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="category_id" value="{{ $category->id }}">
                            <label class="admin-acquisition-field">
                                <span class="form-label">Код</span>
                                <input class="form-control" type="text" name="code" value="{{ $service->code }}" required>
                            </label>
                            <label class="admin-acquisition-field">
                                <span class="form-label">Название</span>
                                <input class="form-control" type="text" name="name" value="{{ $service->name }}" required>
                            </label>
                            <label class="admin-acquisition-field admin-acquisition-grid__wide">
                                <span class="form-label">Описание</span>
                                <textarea class="form-control" name="description" rows="2">{{ $service->description }}</textarea>
                            </label>
                            <label class="admin-acquisition-field">
                                <span class="form-label">Порядок</span>
                                <input class="form-control" type="number" name="sort_order" min="0" value="{{ $service->sort_order }}" required>
                            </label>
                            <label class="form-toggle">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" @checked($service->is_active)>
                                <span>Активна</span>
                            </label>
                            <div class="admin-acquisition-save admin-acquisition-grid__wide">
                                <button class="btn btn--secondary btn--sm" type="submit">Сохранить услугу</button>
                            </div>
                        </form>

                        <div class="admin-acquisition-grid">
                            <form method="POST" action="{{ route('admin.pricing.prices.store') }}" class="admin-acquisition-field">
                                @csrf
                                <input type="hidden" name="service_id" value="{{ $service->id }}">
                                <label class="admin-acquisition-field">
                                    <span class="form-label">Для чего цена</span>
                                    <select class="form-select" name="variant_id">
                                        <option value="">Базовая услуга</option>
                                        @foreach($service->variants as $variant)
                                            <option value="{{ $variant->id }}">{{ $variant->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="admin-acquisition-field">
                                    <span class="form-label">Цена, ₽</span>
                                    <input class="form-control" type="number" name="amount_rub" min="0.01" step="0.01" required>
                                </label>
                                <button class="btn btn--primary btn--sm" type="submit">Установить новую цену</button>
                            </form>

                            <form method="POST" action="{{ route('admin.pricing.variants.store') }}" class="admin-acquisition-field">
                                @csrf
                                <input type="hidden" name="service_id" value="{{ $service->id }}">
                                <input type="hidden" name="sort_order" value="0">
                                <input type="hidden" name="is_active" value="1">
                                <label class="admin-acquisition-field">
                                    <span class="form-label">Код нового варианта</span>
                                    <input class="form-control" type="text" name="code" placeholder="variant_code" required>
                                </label>
                                <label class="admin-acquisition-field">
                                    <span class="form-label">Название варианта</span>
                                    <input class="form-control" type="text" name="name" required>
                                </label>
                                <input type="hidden" name="description" value="">
                                <button class="btn btn--secondary btn--sm" type="submit">Добавить вариант</button>
                            </form>
                        </div>

                        @if($basePrice)
                            <form method="POST" action="{{ route('admin.pricing.prices.deactivate', $basePrice) }}">
                                @csrf
                                @method('PATCH')
                                <button class="btn btn--secondary btn--sm" type="submit">Отключить базовую цену {{ $basePrice->formattedAmount() }}</button>
                            </form>
                        @endif

                        @if($service->variants->isNotEmpty())
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead>
                                    <tr>
                                        <th>Вариант</th>
                                        <th>Код</th>
                                        <th>Цена</th>
                                        <th>Статус</th>
                                        <th>Действия</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($service->variants as $variant)
                                        @php($variantPrice = $currentPrices->get($priceKey($service->id, $variant->id)))
                                        <tr>
                                            <td>{{ $variant->name }}</td>
                                            <td><code>{{ $variant->code }}</code></td>
                                            <td>{{ $variantPrice?->formattedAmount() ?? '—' }}</td>
                                            <td>{{ $variant->is_active ? 'активен' : 'отключён' }}</td>
                                            <td>
                                                <div class="admin-row-actions">
                                                    @if($variantPrice)
                                                        <form method="POST" action="{{ route('admin.pricing.prices.deactivate', $variantPrice) }}">
                                                            @csrf
                                                            @method('PATCH')
                                                            <button class="btn btn--secondary btn--sm" type="submit">Отключить цену</button>
                                                        </form>
                                                    @endif
                                                    <form method="POST" action="{{ route('admin.pricing.variants.destroy', $variant) }}" onsubmit="return confirm('Удалить вариант из активного каталога?')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button class="btn btn--danger btn--sm" type="submit">Удалить</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="5">
                                                <form method="POST" action="{{ route('admin.pricing.variants.update', $variant) }}" class="admin-acquisition-grid">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="hidden" name="service_id" value="{{ $service->id }}">
                                                    <label class="admin-acquisition-field">
                                                        <span class="form-label">Код</span>
                                                        <input class="form-control" type="text" name="code" value="{{ $variant->code }}" required>
                                                    </label>
                                                    <label class="admin-acquisition-field">
                                                        <span class="form-label">Название</span>
                                                        <input class="form-control" type="text" name="name" value="{{ $variant->name }}" required>
                                                    </label>
                                                    <label class="admin-acquisition-field admin-acquisition-grid__wide">
                                                        <span class="form-label">Описание</span>
                                                        <textarea class="form-control" name="description" rows="2">{{ $variant->description }}</textarea>
                                                    </label>
                                                    <label class="admin-acquisition-field">
                                                        <span class="form-label">Порядок</span>
                                                        <input class="form-control" type="number" name="sort_order" min="0" value="{{ $variant->sort_order }}" required>
                                                    </label>
                                                    <label class="form-toggle">
                                                        <input type="hidden" name="is_active" value="0">
                                                        <input type="checkbox" name="is_active" value="1" @checked($variant->is_active)>
                                                        <span>Активен</span>
                                                    </label>
                                                    <div class="admin-acquisition-save admin-acquisition-grid__wide">
                                                        <button class="btn btn--secondary btn--sm" type="submit">Сохранить вариант</button>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </section>
                @endforeach
            </section>
        @empty
            <div class="admin-empty">Категорий пока нет.</div>
        @endforelse

        <section class="admin-acquisition-card">
            <div>
                <h2>История цен</h2>
                <p class="admin-muted">Последние 50 версий. Старые цены не редактируются и остаются доступны для финансовой истории.</p>
            </div>

            @if($priceHistory->isEmpty())
                <div class="admin-empty">История цен пока пуста.</div>
            @else
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                        <tr>
                            <th>Услуга</th>
                            <th>Вариант</th>
                            <th>Цена</th>
                            <th>Действует с</th>
                            <th>Действует до</th>
                            <th>Статус</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($priceHistory as $price)
                            <tr>
                                <td>{{ $price->service?->name ?? '#'.$price->service_id }}</td>
                                <td>{{ $price->variant?->name ?? 'Базовая' }}</td>
                                <td>{{ $price->formattedAmount() }}</td>
                                <td>{{ $price->valid_from?->format('d.m.Y H:i') }}</td>
                                <td>{{ $price->valid_until?->format('d.m.Y H:i') ?? '—' }}</td>
                                <td>{{ $price->is_active ? 'активна' : 'архив' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
