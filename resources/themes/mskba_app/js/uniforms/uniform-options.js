// Shared prototype presets. In a later release these become server-owned catalog data.
export const uniformPalettes = Object.freeze([
  { id: 'mskba', label: 'MSKBA', base: '#111924', trim: '#ff821a', accent: '#ffffff' },
  { id: 'ice', label: 'Металлик', base: '#f3f8fc', trim: '#48aedb', accent: '#184e74' },
  { id: 'royal', label: 'Королевский', base: '#1d367d', trim: '#f6c65d', accent: '#ffffff' },
  { id: 'red', label: 'Бордовый', base: '#8b1e36', trim: '#f7f7f7', accent: '#ffffff' },
]);
export const uniformFaq = Object.freeze({
  selection: { section: 'uniforms', article: 'faq-uniform-selection', title: 'Как подобрать готовую форму', url: '/faq/faq-uniform-selection' },
  workshop: { section: 'uniforms', article: 'faq-uniform-workshop', title: 'Как работает Мастерская MSKBA', url: '/faq/faq-uniform-workshop' },
  production: { section: 'uniforms', article: 'faq-uniform-production', title: 'Как заказать изготовление формы', url: '/faq/faq-uniform-production' },
  partners: { section: 'partnership', article: 'faq-uniform-partnership', title: 'Как стать партнёром MSKBA', url: '/faq/faq-uniform-partnership' },
});
