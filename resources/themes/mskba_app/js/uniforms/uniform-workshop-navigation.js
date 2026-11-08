// Clockwise order from the front is toward the player's left side.
export const UNIFORM_VIEW_ORDER = Object.freeze(['front', 'left', 'rear', 'right']);
export const UNIFORM_SIZE_OPTIONS = Object.freeze(['XS', 'S', 'M', 'L', 'XL', 'XXL']);

export function cycleUniformView(view, direction) {
  const current = UNIFORM_VIEW_ORDER.indexOf(view);
  const index = current < 0 ? 0 : current;
  const step = direction >= 0 ? 1 : -1;
  return UNIFORM_VIEW_ORDER[(index + step + UNIFORM_VIEW_ORDER.length) % UNIFORM_VIEW_ORDER.length];
}

// Preserve older designs with many sizes, but choose only one active size.
export function singleUniformSize(sizes) {
  if (!Array.isArray(sizes)) return 'M';
  return sizes.find(size => UNIFORM_SIZE_OPTIONS.includes(size)) || 'M';
}
