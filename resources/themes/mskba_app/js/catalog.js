// Static design specimen only: no authentication, API calls or persisted form data.
const dialog = document.querySelector('#sample-dialog');
const toast = document.querySelector('#sample-toast');
let opener;
let dialogFrame;
function fitDialogToViewport() {
  if (!dialog.open) return;
  const viewport = window.visualViewport;
  // CSS zoom and browser/pinch zoom use different coordinate spaces.
  const zoom = dialog.getBoundingClientRect().width / parseFloat(getComputedStyle(dialog).width) || 1;
  const width = (viewport?.width ?? window.innerWidth) / zoom;
  const height = (viewport?.height ?? window.innerHeight) / zoom;
  dialog.style.setProperty('--dialog-visible-width', `${width}px`);
  dialog.style.setProperty('--dialog-visible-height', `${height}px`);
  dialog.style.setProperty('--dialog-center-x', `${((viewport?.offsetLeft ?? 0) + (viewport?.width ?? window.innerWidth) / 2) / zoom}px`);
  dialog.style.setProperty('--dialog-center-y', `${((viewport?.offsetTop ?? 0) + (viewport?.height ?? window.innerHeight) / 2) / zoom}px`);
  dialog.classList.toggle('dialog-compact', height <= 400 || width <= 360);
}
function scheduleDialogFit() {
  cancelAnimationFrame(dialogFrame);
  dialogFrame = requestAnimationFrame(fitDialogToViewport);
}
window.addEventListener('resize', scheduleDialogFit);
window.visualViewport?.addEventListener('resize', scheduleDialogFit);
window.visualViewport?.addEventListener('scroll', scheduleDialogFit);
document.querySelectorAll('[data-open-dialog]').forEach(button => {
  button.addEventListener('click', () => {
    opener = button;
    dialog.querySelector('.dialog-body').scrollTop = 0;
    dialog.showModal();
    fitDialogToViewport();
    scheduleDialogFit();
  });
});
document.querySelector('#close-dialog').addEventListener('click', () => dialog.close());
document.querySelector('#cancel-dialog').addEventListener('click', () => dialog.close());
document.querySelector('#confirm-dialog').addEventListener('click', () => dialog.close());
dialog.addEventListener('close', () => {
  cancelAnimationFrame(dialogFrame);
  opener?.focus({ preventScroll: true });
});
// Keep keyboard cycling within the specimen, including the browser's boundary Tab.
dialog.addEventListener('keydown', event => {
  if (event.key !== 'Tab') return;
  const focusable = [...dialog.querySelectorAll('button:not(:disabled), [tabindex="0"]')];
  const first = focusable[0];
  const last = focusable.at(-1);
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
});
document.querySelectorAll('[data-notice]').forEach(button => {
  button.addEventListener('click', () => { toast.hidden = false; });
});
document.querySelector('#close-toast').addEventListener('click', () => { toast.hidden = true; });

const form = document.querySelector('#sample-form');
const nameField = document.querySelector('#game-name');
const nameError = document.querySelector('#game-name-error');
const formResult = document.querySelector('#form-result');
form.addEventListener('submit', event => {
  event.preventDefault();
  const invalid = nameField.value.trim().length === 0;
  nameError.hidden = !invalid;
  nameField.setAttribute('aria-invalid', String(invalid));
  nameField.setAttribute('aria-describedby', invalid ? 'game-name-help game-name-error' : 'game-name-help');
  formResult.textContent = invalid ? '' : 'Образец заполнен. Данные никуда не отправлены.';
  if (invalid) nameField.focus();
});

let selectedFormat = 'all';
const search = document.querySelector('#event-search');
const eventCards = [...document.querySelectorAll('[data-event]')];
// Measure only the fade HEIGHT. Its bottom is anchored to the image in CSS.
const cardSizeObserver = new ResizeObserver(entries => {
  for (const { target, borderBoxSize } of entries) {
    const height = borderBoxSize[0]?.blockSize ?? target.offsetHeight;
    if (!height) continue;
    target.style.setProperty('--media-fade-height', `${height * 0.25}px`);
    target.style.setProperty('--media-fade-active-height', `${height * 0.35}px`);
  }
});
eventCards.forEach(card => cardSizeObserver.observe(card));
const updateEvents = () => {
  const query = search.value.trim().toLocaleLowerCase('ru');
  let count = 0;
  for (const card of eventCards) {
    const visible = (selectedFormat === 'all' || card.dataset.event === selectedFormat)
      && card.textContent.toLocaleLowerCase('ru').includes(query);
    card.hidden = !visible;
    if (visible) count += 1;
  }
  document.querySelector('#search-empty').hidden = count !== 0;
  document.querySelector('#team-sample').hidden = count === 0;
  document.querySelector('#search-status').textContent = `Найдено демонстрационных событий: ${count}`;
};
search.addEventListener('input', updateEvents);
document.querySelectorAll('[data-format]').forEach(button => {
  button.addEventListener('click', () => {
    selectedFormat = button.dataset.format;
    document.querySelectorAll('[data-format]').forEach(item => {
      item.setAttribute('aria-pressed', String(item === button));
    });
    updateEvents();
  });
});
document.querySelector('#reset-search').addEventListener('click', () => {
  search.value = '';
  document.querySelector('[data-format="all"]').click();
  search.focus();
});

const navigationLinks = [...document.querySelectorAll('.desktop-nav a, .bottom-nav a')];
function updateNavigation() {
  const hash = location.hash || '#overview';
  navigationLinks.forEach(link => {
    if (link.hash === hash) link.setAttribute('aria-current', 'location');
    else link.removeAttribute('aria-current');
  });
}
window.addEventListener('hashchange', updateNavigation);
updateNavigation();