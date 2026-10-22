/* MSKBA Tournaments Scene — Filament media + real confirmed upcoming events.
 * Does not touch hero/venues scroll directors. Artwork is optional until uploaded.
 */
(() => {
  'use strict';
  const root = document.querySelector('.mskba-tournaments');
  if (!root || root.dataset.tournamentsReady === '1') return;
  root.dataset.tournamentsReady = '1';

  const bg = root.querySelector('.mskba-tournaments__background');
  const rim = root.querySelector('.mskba-tournaments__rim');
  const ball = root.querySelector('.mskba-tournaments__ball');
  const list = root.querySelector('.mskba-tournaments__listing-items');
  if (!bg || !rim || !ball || !list) return;

  const siteAsset = value => {
    if (typeof value !== 'string' || !/^\/media\/site\/[a-f0-9-]{36}$/.test(value)) return '';
    return value;
  };
  const sceneArt = (node, path) => {
    const url = siteAsset(path);
    if (!url) return;
    // CSS background-image avoids early "broken image" icons before Filament media uploads.
    node.style.backgroundImage = 'url("' + url + '")';
  };
  const add = (tag, className, text) => {
    const el = document.createElement(tag);
    el.className = className;
    if (text !== undefined) el.textContent = String(text);
    return el;
  };
  const showEmpty = text => {
    list.replaceChildren(add('p', 'mskba-tournaments__empty', text));
  };
  const localTournamentUrl = value => {
    if (typeof value !== 'string') return '/tournaments';
    try {
      const url = new URL(value, location.origin);
      return url.origin === location.origin && /^\/tournaments\/[A-Za-z0-9-]+\/?$/.test(url.pathname)
        ? url.pathname
        : '/tournaments';
    } catch (_) { return '/tournaments'; }
  };
  const calendarDate = input => {
    if (typeof input !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(input)) return null;
    const date = new Date(input + 'T12:00:00');
    return Number.isNaN(date.getTime()) ? null : date;
  };
  const render = tournaments => {
    list.replaceChildren();
    if (!Array.isArray(tournaments) || !tournaments.length) {
      showEmpty('Анонсы ближайших турниров появятся здесь.');
      return;
    }
    for (const item of tournaments.slice(0, 3)) {
      const link = add('a', 'mskba-tournaments__listing-item');
      link.href = localTournamentUrl(item.url);
      const date = calendarDate(item.date);
      const dateBox = add('span', 'mskba-tournaments__date');
      dateBox.append(
        add('span', 'mskba-tournaments__day', date
          ? new Intl.DateTimeFormat('ru-RU',{day:'2-digit'}).format(date) : '—'),
        add('span', 'mskba-tournaments__month', date
          ? new Intl.DateTimeFormat('ru-RU',{month:'short'}).format(date).toUpperCase() : '')
      );
      const detail = add('span', 'mskba-tournaments__item-detail');
      detail.append(add('span', 'mskba-tournaments__item-name', item.title || 'Турнир'));
      if (typeof item.format === 'string' && item.format.trim()) {
        detail.append(add('span', 'mskba-tournaments__item-meta', item.format));
      }
      const arrow = add('span', 'mskba-tournaments__item-arrow', '→');
      arrow.setAttribute('aria-hidden', 'true');
      link.append(dateBox,detail,arrow);
      list.append(link);
    }
  };
  const load = async () => {
    try {
      const response = await fetch('/api/v1/home/tournaments-scene', {
        credentials: 'same-origin',
        headers: {Accept:'application/json'}
      });
      if (!response.ok) throw new Error('Tournament feed unavailable');
      const data = await response.json();
      sceneArt(bg,data.assets?.background);
      sceneArt(rim,data.assets?.rim);
      sceneArt(ball,data.assets?.ball);
      render(data.tournaments);
    } catch (error) {
      console.warn('[MSKBA Tournaments]', error);
      showEmpty('Ближайшие турниры можно посмотреть в каталоге.');
    }
  };
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      if (entries.some(x => x.isIntersecting)) {
        observer.disconnect();
        load();
      }
    },{rootMargin:'250px'});
    observer.observe(root);
  } else {
    load();
  }
})();
