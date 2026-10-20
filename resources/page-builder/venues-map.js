/* MSKBA Venues Map · v1; published and rollbackable through Filament's JS editor. */
(() => {
  'use strict';
  const root = document.querySelector('.mskba-venues-map');
  if (!root || root.dataset.venuesMapInitialized === '1') return;
  root.dataset.venuesMapInitialized = '1';
  const canvas = root.querySelector('#mskba-venues-map-canvas');
  const toolbar = root.querySelector('#mskba-venues-map-filters');
  const status = root.querySelector('#mskba-venues-map-status');
  if (!canvas || !toolbar) return;
  const say = message => { if (status) status.textContent = message; };
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[ch]);
  const localUrl = (value, fallback) => {
    try {
      const url = new URL(String(value ?? ''), location.origin);
      return url.origin === location.origin ? url.href : fallback;
    } catch (_) { return fallback; }
  };
  const validCoordinate = v => Number.isFinite(Number(v.latitude)) &&
    Number.isFinite(Number(v.longitude)) && Number(v.latitude) >= -90 &&
    Number(v.latitude) <= 90 && Number(v.longitude) >= -180 && Number(v.longitude) <= 180;
  const all = [];
  const pageSize = 100;
  const endpoint = '/api/v1/home/venues-map';
  async function getVenues() {
    let next = null;
    let key = '';
    for (let page = 0; page < 10; page++) {
      const url = new URL(endpoint, location.origin);
      url.searchParams.set('limit', String(pageSize));
      if (next) url.searchParams.set('cursor', next);
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('Не удалось получить список площадок');
      const json = await response.json();
      if (!Array.isArray(json.data)) throw new Error('Неверный формат данных площадок');
      all.push(...json.data.filter(validCoordinate));
      key ||= (typeof json.maps_api_key === 'string' ? json.maps_api_key : '');
      if (!json.meta?.has_more || !json.meta?.next_cursor) break;
      next = json.meta.next_cursor;
    }
    return key;
  }
  function yandexReady(key) {
    if (window.ymaps && typeof window.ymaps.ready === 'function') {
      return new Promise(resolve => window.ymaps.ready(resolve));
    }
    if (!key) return Promise.reject(new Error('Ключ Яндекс Карт не настроен'));
    if (!window.mskbaYandexMapsLoading) {
      window.mskbaYandexMapsLoading = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://api-maps.yandex.ru/2.1/?' +
          new URLSearchParams({ apikey: key, lang: 'ru_RU' }).toString();
        script.async = true;
        script.onload = resolve;
        script.onerror = () => reject(new Error('Карта недоступна'));
        document.head.appendChild(script);
      }).catch(error => {
        window.mskbaYandexMapsLoading = null;
        throw error;
      });
    }
    return window.mskbaYandexMapsLoading.then(() => new Promise(resolve => window.ymaps.ready(resolve)));
  }
  function buildToolbar(onChange) {
    toolbar.replaceChildren();
    const specs = [
      { key: 'district', label: 'Все районы', values: all.map(v => v.district) },
      { key: 'surface', label: 'Все типы покрытий', values: all.flatMap(v => v.surfaces || []).map(x => x.label) },
      { key: 'amenity', label: 'Все удобства', values: all.flatMap(v => v.amenities || []) }
    ];
    const controls = {};
    for (const spec of specs) {
      const wrapper = document.createElement('div');
      wrapper.className = 'mskba-venues-map__filter-wrap';
      const select = document.createElement('select');
      select.className = 'mskba-venues-map__select';
      select.setAttribute('aria-label', spec.label);
      select.add(new Option(spec.label, ''));
      const values = [...new Set(spec.values.filter(Boolean).map(String))].sort((a,b) => a.localeCompare(b,'ru'));
      values.forEach(v => select.add(new Option(v,v)));
      select.disabled = values.length === 0;
      select.addEventListener('change', onChange);
      controls[spec.key] = select;
      wrapper.append(select);
      toolbar.append(wrapper);
    }
    const reset = document.createElement('button');
    reset.type = 'button'; reset.className = 'mskba-venues-map__reset';
    reset.textContent = '↻ Сбросить';
    reset.addEventListener('click', () => {
      Object.values(controls).forEach(select => { select.value = ''; });
      onChange();
    });
    toolbar.append(reset);
    return controls;
  }
  function popupContent(venue) {
    const href = localUrl(venue.url, '/venues');
    const imageUrl = venue.image_url ? localUrl(venue.image_url, '') : '';
    const image = imageUrl ? '<img alt="" src="' + escapeHtml(imageUrl) + '">' : '';
    return '<div class="mskba-venues-map__popup">' + image +
      '<strong>' + escapeHtml(venue.name || 'Площадка') + '</strong>' +
      '<p>' + escapeHtml(venue.address || '') + '</p>' +
      '<a href="' + escapeHtml(href) + '">Подробнее →</a></div>';
  }
  async function initialize() {
    say('Загружаем площадки…');
    const key = await getVenues();
    if (!all.length) {
      say('Подтверждённых площадок с координатами пока нет. Откройте каталог.');
      return;
    }
    await yandexReady(key);
    const ym = window.ymaps;
    const map = new ym.Map(canvas, {
      center: [55.751244, 37.618423], zoom: 10, controls: ['zoomControl']
    }, { suppressMapOpenBlock: true });
    map.behaviors.disable('scrollZoom');
    if (matchMedia('(pointer: coarse)').matches) {
      map.behaviors.disable('drag');
      map.behaviors.enable('multiTouch');
    }
    const pinLayout = ym.templateLayoutFactory.createClass(
      '<div class="mskba-venues-map__pin"><span>🏀</span></div>'
    );
    const clusterer = new ym.Clusterer({
      preset: 'islands#invertedOrangeClusterIcons',
      gridSize: 64, clusterDisableClickZoom: false,
      clusterOpenBalloonOnClick: false, groupByCoordinates: false
    });
    map.geoObjects.add(clusterer);
    const placemarks = all.map(v => ({
      venue: v,
      marker: new ym.Placemark([Number(v.latitude), Number(v.longitude)], {
        hintContent: escapeHtml(v.name),
        balloonContent: popupContent(v)
      }, {
        iconLayout: pinLayout,
        iconShape: { type: 'Rectangle', coordinates: [[-20,-20],[20,30]] },
        iconOffset: [-20,-20],
        hideIconOnBalloonOpen: false
      })
    }));
    const controls = buildToolbar(refresh);
    function refresh() {
      const district = controls.district.value;
      const surface = controls.surface.value;
      const amenity = controls.amenity.value;
      const displayed = placemarks.filter(({venue}) =>
        (!district || venue.district === district) &&
        (!surface || (venue.surfaces || []).some(v => v.label === surface)) &&
        (!amenity || (venue.amenities || []).includes(amenity))
      ).map(v => v.marker);
      clusterer.removeAll();
      clusterer.add(displayed);
      say(displayed.length ? 'Площадок на карте: ' + displayed.length : 'По выбранным фильтрам площадок не найдено');
    }
    refresh();
    const observer = typeof ResizeObserver === 'function'
      ? new ResizeObserver(() => { if (canvas.isConnected && canvas.offsetWidth) map.container.fitToViewport(); })
      : null;
    observer?.observe(canvas);
    window.addEventListener('pagehide', () => observer?.disconnect(), { once: true });
  }
  const start = () => initialize().catch(error => {
    console.error('[MSKBA Venues Map]', error);
    say(error.message || 'Карта временно недоступна');
  });
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      if (!entries.some(item => item.isIntersecting)) return;
      observer.disconnect();
      start();
    }, { rootMargin: '300px' });
    observer.observe(root);
  } else {
    start();
  }
})();
