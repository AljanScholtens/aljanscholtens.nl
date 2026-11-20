// Photo lightbox on a native <dialog>. Links with data-lightbox="<group>"
// open their href; photos with the same group can be browsed with the
// buttons, the arrow keys or a swipe. Escape and a click beside the photo close it.

const SWIPE_DISTANCE = 50;

function icon(name) {
  return `<svg class="c-icon c-icon--stroke" aria-hidden="true"><use href="#icon-${name}"></use></svg>`;
}

function createDialog(labels) {
  const dialog = document.createElement('dialog');
  dialog.className = 'c-lightbox';
  dialog.setAttribute('aria-label', labels.dialog);
  dialog.innerHTML = `
    <figure class="c-lightbox__figure">
      <img class="c-lightbox__image" alt="">
      <figcaption class="c-lightbox__caption"></figcaption>
    </figure>
    <p class="c-lightbox__counter" aria-live="polite"></p>
    <button class="c-lightbox__button c-lightbox__button--close" type="button" aria-label="${labels.close}">${icon('close')}</button>
    <button class="c-lightbox__button c-lightbox__button--prev" type="button" aria-label="${labels.prev}">${icon('arrow-right')}</button>
    <button class="c-lightbox__button c-lightbox__button--next" type="button" aria-label="${labels.next}">${icon('arrow-right')}</button>
  `;
  document.body.append(dialog);
  return dialog;
}

export function initLightbox() {
  const links = [...document.querySelectorAll('a[data-lightbox]')];
  if (!links.length) return;

  const english = document.documentElement.lang.startsWith('en');
  const labels = english
    ? { dialog: 'Photo', close: 'Close', prev: 'Previous photo', next: 'Next photo', of: 'of' }
    : { dialog: 'Foto', close: 'Sluiten', prev: 'Vorige foto', next: 'Volgende foto', of: 'van' };

  const dialog = createDialog(labels);
  const image = dialog.querySelector('.c-lightbox__image');
  const caption = dialog.querySelector('.c-lightbox__caption');
  const counter = dialog.querySelector('.c-lightbox__counter');
  const prevButton = dialog.querySelector('.c-lightbox__button--prev');
  const nextButton = dialog.querySelector('.c-lightbox__button--next');

  let group = [];
  let index = 0;

  function preload(i) {
    const link = group[i];
    if (link) new Image().src = link.href;
  }

  function show(i) {
    index = (i + group.length) % group.length;
    const link = group[index];
    const alt = link.querySelector('img')?.alt ?? '';

    dialog.classList.add('is-loading');
    image.onload = () => dialog.classList.remove('is-loading');
    image.src = link.href;
    image.alt = alt;
    caption.textContent = alt;
    caption.hidden = !alt;

    const single = group.length < 2;
    prevButton.hidden = single;
    nextButton.hidden = single;
    counter.hidden = single;
    counter.textContent = `${index + 1} ${labels.of} ${group.length}`;

    preload(index + 1);
    preload(index - 1);
  }

  function open(link) {
    group = links.filter((other) => other.dataset.lightbox === link.dataset.lightbox);
    show(group.indexOf(link));
    dialog.showModal();
  }

  links.forEach((link) => {
    link.addEventListener('click', (event) => {
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
      event.preventDefault();
      open(link);
    });
  });

  prevButton.addEventListener('click', () => show(index - 1));
  nextButton.addEventListener('click', () => show(index + 1));
  dialog.querySelector('.c-lightbox__button--close').addEventListener('click', () => dialog.close());

  dialog.addEventListener('keydown', (event) => {
    if (group.length < 2) return;
    if (event.key === 'ArrowLeft') show(index - 1);
    if (event.key === 'ArrowRight') show(index + 1);
  });

  // A click on the backdrop or the empty space around the photo closes it.
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog || event.target.classList.contains('c-lightbox__figure')) dialog.close();
  });

  let startX = null;
  dialog.addEventListener('pointerdown', (event) => {
    if (event.pointerType !== 'mouse') startX = event.clientX;
  });
  dialog.addEventListener('pointerup', (event) => {
    if (startX === null || group.length < 2) return;
    const distance = event.clientX - startX;
    startX = null;
    if (Math.abs(distance) > SWIPE_DISTANCE) show(index + (distance < 0 ? 1 : -1));
  });

  dialog.addEventListener('close', () => {
    image.removeAttribute('src');
  });
}
