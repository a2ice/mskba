/* MSKBA Venues Map V2 — real venues, Yandex Map 2.1, accessible custom popup. */
(() => {
  'use strict';
  const root = document.querySelector('.mskba-venues-map');
  if (!root || root.dataset.venuesMapInitialized === '1') return;
  root.dataset.venuesMapInitialized = '1';
  const canvas = root.querySelector('#mskba-venues-map-canvas');
  const toolbar = root.querySelector('#mskba-venues-map-filters');
  const cardMount = root.querySelector('#mskba-venues-map-card');
  const status = root.querySelector('#mskba-venues-map-status');
  if (!canvas || !toolbar || !cardMount) return;

  // The map canvas already fills the card. Uncover it by sliding the text and
  // full-width white gradient off the left edge; preserve /venues link as the
  // no-JavaScript fallback. Controls and markers remain interactive.
  const copy = root.querySelector('.mskba-venues-map__copy');
  const scrim = root.querySelector('.mskba-venues-map__scrim');
  const expandLink = copy?.querySelector('a.section-cta');
  let refreshMapViewport = () => {};
  if (copy && scrim && expandLink) {
    const collapseButton = document.createElement('button');
    collapseButton.type = 'button';
    collapseButton.className = 'mskba-venues-map__collapse';
    collapseButton.setAttribute('aria-label', 'Свернуть карту и вернуть описание');
    collapseButton.setAttribute('title', 'Вернуть описание карты');
    collapseButton.setAttribute('aria-controls', canvas.id);
    collapseButton.setAttribute('aria-expanded', 'false');
    collapseButton.tabIndex = -1;
    const arrow = document.createElement('span');
    arrow.textContent = '←';
    arrow.setAttribute('aria-hidden', 'true');
    collapseButton.append(arrow);
    root.append(collapseButton);

    let expanded = false;
    expandLink.setAttribute('role', 'button');
    expandLink.setAttribute('aria-controls', canvas.id);
    expandLink.setAttribute('aria-expanded', 'false');
    const toggleMap = next => {
      if (next === expanded) return;
      expanded = next;
      root.classList.toggle('is-map-expanded', expanded);
      copy.inert = expanded;
      expandLink.setAttribute('aria-expanded', String(expanded));
      collapseButton.setAttribute('aria-expanded', String(expanded));
      collapseButton.tabIndex = expanded ? 0 : -1;
      if (expanded) collapseButton.focus({ preventScroll: true });
      else expandLink.focus({ preventScroll: true });
      window.requestAnimationFrame(refreshMapViewport);
    };
    expandLink.addEventListener('click', event => {
      event.preventDefault();
      toggleMap(true);
    });
    collapseButton.addEventListener('click', () => toggleMap(false));
    root.addEventListener('transitionend', event => {
      if (event.target === copy || event.target === scrim) refreshMapViewport();
    });
    window.addEventListener('keydown', event => {
      if (event.key === 'Escape' && expanded) toggleMap(false);
    });
  }

  const say = value => { if (status) status.textContent = value; };
  const svgPaths = {
    pin: '<path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
    sliders: '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
    star: '<path d="m12 2 3.1 6.4 7.1 1-5.1 5 1.2 7-6.3-3.3-6.3 3.3 1.2-7-5.1-5 7.1-1Z"/>',
    calendar: '<rect width="18" height="17" x="3" y="5" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/>',
    layers: '<path d="m12 2 9 5-9 5-9-5 9-5ZM3 12l9 5 9-5M3 17l9 5 9-5"/>',
    reset: '<path d="M3 12a9 9 0 1 0 3-6.7M3 3v6h6"/>',
    close: '<path d="M18 6 6 18M6 6l12 12"/>',
    arrow: '<path d="M5 12h14m-6-6 6 6-6 6"/>',
    basketball: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3v18M5.6 5.6c6.4 2 8.3 9.1 8.8 14M18.4 5.6c-6.4 2-8.3 9.1-8.8 14"/>'
  };
  function icon(name) {
    const namespace = 'http://www.w3.org/2000/svg';
    const node = document.createElementNS(namespace, 'svg');
    node.setAttribute('viewBox','0 0 24 24');
    node.setAttribute('width','24');
    node.setAttribute('height','24');
    node.setAttribute('fill','none');
    node.setAttribute('stroke','currentColor');
    node.setAttribute('stroke-width','2');
    node.setAttribute('stroke-linecap','round');
    node.setAttribute('stroke-linejoin','round');
    node.setAttribute('aria-hidden','true');
    // All path markup comes exclusively from this fixed, audited dictionary.
    node.innerHTML = svgPaths[name] || svgPaths.pin;
    return node;
  }
  root.querySelectorAll('.mskba-venues-map__feature-icon').forEach((slot, index) => {
    slot.replaceChildren(icon(['pin','sliders','star','calendar'][index]));
  });
  const escapeHint = value => String(value || '').replace(/[&<>"']/g, ch => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
  })[ch]);
  const safeUrl = (value, fallback) => {
    try {
      const url = new URL(String(value || ''), location.origin);
      return url.origin === location.origin && /^https?:$/.test(url.protocol) ? url.href : fallback;
    } catch (_) { return fallback; }
  };
  const validCoordinate = venue => {
    if (venue.latitude === null || venue.longitude === null ||
        venue.latitude === undefined || venue.longitude === undefined) return false;
    const lat = Number(venue.latitude), lon = Number(venue.longitude);
    return Number.isFinite(lat) && Number.isFinite(lon) &&
      lat >= -90 && lat <= 90 && lon >= -180 && lon <= 180;
  };
  const venues = [];
  const endpoint = '/api/v1/home/venues-map';
  async function getVenues() {
    let cursor = '';
    let key = '';
    for (let page = 0; page < 10; page++) {
      const url = new URL(endpoint,location.origin);
      url.searchParams.set('limit','100');
      if (cursor) url.searchParams.set('cursor',cursor);
      const response = await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
      if (!response.ok) throw new Error('Не удалось получить площадки');
      const json = await response.json();
      if (!Array.isArray(json.data)) throw new Error('Неверный формат ответа сервера');
      venues.push(...json.data.filter(validCoordinate));
      if (typeof json.maps_api_key === 'string') key ||= json.maps_api_key;
      cursor = json.meta?.has_more && json.meta?.next_cursor ? json.meta.next_cursor : '';
      if (!cursor) break;
    }
    return key;
  }
  function yandexReady(key) {
    if (window.ymaps?.ready) return new Promise(resolve => window.ymaps.ready(resolve));
    if (!key) return Promise.reject(new Error('Ключ Яндекс Карт не настроен'));
    if (!window.mskbaYandexMapsLoading) {
      window.mskbaYandexMapsLoading = new Promise((resolve,reject) => {
        const script = document.createElement('script');
        script.src = 'https://api-maps.yandex.ru/2.1/?' +
          new URLSearchParams({apikey:key,lang:'ru_RU'}).toString();
        script.async = true;
        script.onload = resolve;
        script.onerror = () => reject(new Error('Не удалось загрузить карту'));
        document.head.append(script);
      }).catch(error => { window.mskbaYandexMapsLoading = null; throw error; });
    }
    return window.mskbaYandexMapsLoading.then(() => new Promise(resolve => window.ymaps.ready(resolve)));
  }

  function buildToolbar(onChange) {
    toolbar.replaceChildren();
    const specs = [
      { key:'district', label:'Все районы', icon:'pin', options:venues.map(v => v.district) },
      { key:'surface', label:'Все типы покрытий', icon:'layers', options:venues.flatMap(v => (v.surfaces || []).map(s => s.label)) },
      { key:'amenity', label:'Все удобства', icon:'star', options:venues.flatMap(v => v.amenities || []) }
    ];
    const controls = {};
    for (const spec of specs) {
      const wrap = document.createElement('div');
      wrap.className = 'mskba-venues-map__filter-wrap';
      const adornment = document.createElement('span');
      adornment.className = 'mskba-venues-map__filter-icon';
      adornment.append(icon(spec.icon));
      const select = document.createElement('select');
      select.className = 'mskba-venues-map__select';
      select.setAttribute('aria-label',spec.label);
      select.add(new Option(spec.label,''));
      const values = [...new Set(spec.options.filter(Boolean).map(String))].sort((a,b) => a.localeCompare(b,'ru'));
      for (const v of values) select.add(new Option(v,v));
      select.disabled = !values.length;
      select.addEventListener('change',onChange);
      wrap.append(adornment,select);
      toolbar.append(wrap);
      controls[spec.key] = select;
    }
    const reset = document.createElement('button');
    reset.type = 'button'; reset.className = 'mskba-venues-map__reset';
    reset.append(icon('reset'),document.createTextNode('Сбросить'));
    reset.addEventListener('click',() => {
      Object.values(controls).forEach(el => { el.value = ''; });
      onChange();
    });
    toolbar.append(reset);
    return controls;
  }

  function makeCard(venue,close) {
    const article = document.createElement('article');
    article.className = 'mskba-venues-map__venue-card';
    article.setAttribute('aria-label','Карточка площадки '+(venue.name || ''));
    const closeButton = document.createElement('button');
    closeButton.type = 'button'; closeButton.className = 'mskba-venues-map__card-close';
    closeButton.setAttribute('aria-label','Закрыть карточку площадки');
    closeButton.append(icon('close'));
    closeButton.addEventListener('click',close);
    const media = document.createElement('div');
    media.className = 'mskba-venues-map__card-media';
    const image = venue.image_url ? safeUrl(venue.image_url,'') : '';
    if (image) {
      const img = document.createElement('img');
      img.src = image; img.alt = 'Фото площадки: '+(venue.name || '');
      img.loading = 'lazy';
      img.onerror = () => {
        img.remove();
        const placeholder = mediaPlaceholder();
        media.prepend(placeholder);
      };
      media.append(img);
    } else {
      media.append(mediaPlaceholder());
    }
    const badge = document.createElement('span');
    badge.className = 'mskba-venues-map__card-label';
    badge.textContent = 'Площадка MSKBA';
    media.append(badge);

    const body = document.createElement('div');
    body.className = 'mskba-venues-map__card-body';
    const name = document.createElement('h3');
    name.className = 'mskba-venues-map__card-name';
    name.textContent = venue.name || 'Баскетбольная площадка';
    const address = document.createElement('p');
    address.className = 'mskba-venues-map__card-address';
    address.append(icon('pin'),document.createTextNode(venue.address || 'Москва'));
    body.append(name,address);

    const tags = [...new Set([
      ...(venue.surfaces || []).slice(0,1).map(x => x.label),
      ...(venue.amenities || []).slice(0,3)
    ].filter(Boolean))];
    if (tags.length) {
      const list = document.createElement('div');
      list.className = 'mskba-venues-map__card-tags';
      for (const tag of tags) {
        const item = document.createElement('span');
        item.className = 'mskba-venues-map__card-tag';
        item.textContent = String(tag);
        list.append(item);
      }
      body.append(list);
    }
    const actions = document.createElement('div');
    actions.className = 'mskba-venues-map__card-actions';
    const link = document.createElement('a');
    link.className = 'mskba-venues-map__card-link';
    link.href = safeUrl(venue.url,'/venues');
    link.append(document.createTextNode('Подробнее'),icon('arrow'));
    actions.append(link);
    body.append(actions);
    article.append(closeButton,media,body);
    return article;
  }
  function mediaPlaceholder() {
    const placeholder = document.createElement('div');
    placeholder.className = 'mskba-venues-map__card-media-placeholder';
    placeholder.append(icon('basketball'),document.createTextNode('Фото площадки пока нет'));
    return placeholder;
  }

  async function initialize() {
    say('Загружаем площадки…');
    const key = await getVenues();
    if (!venues.length) { say('Подтверждённых площадок с координатами пока нет'); return; }
    await yandexReady(key);
    const ym = window.ymaps;
    const isMobile = matchMedia('(max-width:767px)').matches;
    const map = new ym.Map(canvas,{
      center: isMobile ? [55.77,37.55] : [55.78,37.42],
      zoom: isMobile ? 9 : 9.5, controls: ['zoomControl']
    },{suppressMapOpenBlock:true});
    refreshMapViewport = () => {
      if (canvas.isConnected && canvas.offsetWidth) map.container.fitToViewport();
    };
    map.behaviors.disable('scrollZoom');
    if (matchMedia('(pointer: coarse)').matches) {
      map.behaviors.disable('drag');
      map.behaviors.enable('multiTouch');
    }

    const regularLayout = ym.templateLayoutFactory.createClass(
      '<div class="mskba-venues-map__pin"><span></span></div>'
    );
    const activeLayout = ym.templateLayoutFactory.createClass(
      '<div class="mskba-venues-map__pin is-active"><span></span></div>'
    );
    const clusterer = new ym.Clusterer({
      preset:'islands#invertedOrangeClusterIcons', gridSize:55,
      clusterDisableClickZoom:false, clusterOpenBalloonOnClick:false,
      groupByCoordinates:false
    });
    map.geoObjects.add(clusterer);

    const pinOptions = {
      // Include the full CSS-rotated pin and logo, not only its center.
      iconShape:{type:'Rectangle',coordinates:[[-28,-28],[28,30]]},
      iconOffset:[-20,-20], hideIconOnBalloonOpen:false
    };
    let selected = null;
    let selectedMarker = null;
    function closeCard() {
      if (selectedMarker) selectedMarker.options.set('iconLayout',regularLayout);
      selectedMarker = null; selected = null;
      cardMount.hidden = true;
      cardMount.replaceChildren();
      root.classList.remove('has-open-card');
    }
    function openCard(venue,marker) {
      if (selectedMarker) selectedMarker.options.set('iconLayout',regularLayout);
      selected = venue.id; selectedMarker = marker;
      marker.options.set('iconLayout',activeLayout);
      cardMount.replaceChildren(makeCard(venue,closeCard));
      cardMount.hidden = false;
      root.classList.add('has-open-card');
    }

    const items = venues.map(venue => {
      const marker = new ym.Placemark(
        [Number(venue.latitude),Number(venue.longitude)],
        {hintContent:escapeHint(venue.name || 'Площадка')},
        { ...pinOptions,iconLayout:regularLayout }
      );
      marker.events.add('click',() => openCard(venue,marker));
      return {venue,marker};
    });

    const controls = buildToolbar(refresh);
    function refresh() {
      const district = controls.district.value;
      const surface = controls.surface.value;
      const amenity = controls.amenity.value;
      const visible = items.filter(({venue}) =>
        (!district || venue.district === district) &&
        (!surface || (venue.surfaces || []).some(s => s.label === surface)) &&
        (!amenity || (venue.amenities || []).includes(amenity))
      );
      if (selected !== null && !visible.some(x => x.venue.id === selected)) closeCard();
      clusterer.removeAll();
      clusterer.add(visible.map(x => x.marker));
      say(visible.length ? 'Площадок на карте: '+visible.length : 'По выбранным фильтрам площадок не найдено');
    }
    refresh();
    // Start with the map unobstructed. The user opens a venue card by
    // clicking a marker; never show a popup on initial page load.

    const observer = typeof ResizeObserver === 'function'
      ? new ResizeObserver(() => { if (canvas.isConnected && canvas.offsetWidth) map.container.fitToViewport(); })
      : null;
    observer?.observe(canvas);
    window.addEventListener('pagehide',() => observer?.disconnect(),{once:true});
    window.addEventListener('keydown',e => { if (e.key === 'Escape') closeCard(); });
  }
  const start = () => initialize().catch(error => {
    console.error('[MSKBA Venues Map]',error);
    say(error.message || 'Карта временно недоступна');
  });
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      if (!entries.some(x => x.isIntersecting)) return;
      observer.disconnect(); start();
    },{rootMargin:'300px'});
    observer.observe(root);
  } else {
    start();
  }
})();
