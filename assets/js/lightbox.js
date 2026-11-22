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

function thumbnailBounds(thumbnail) {
  if (!thumbnail) return null;
  const bounds = thumbnail.getBoundingClientRect();
  const offscreen = bounds.bottom <= 0 || bounds.right <= 0 || bounds.top >= innerHeight || bounds.left >= innerWidth;
  return bounds.width && bounds.height && !offscreen ? bounds : null;
}

function containedBounds(bounds, width, height) {
  const scale = Math.min(bounds.width / width, bounds.height / height);
  const fittedWidth = width * scale;
  const fittedHeight = height * scale;
  return {
    left: bounds.left + (bounds.width - fittedWidth) / 2,
    top: bounds.top + (bounds.height - fittedHeight) / 2,
    width: fittedWidth,
    height: fittedHeight,
  };
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
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');

  let group = [];
  let index = 0;
  let motion;
  let source;
  let photoRequest = 0;

  function photoBounds() {
    const width = Number(image.getAttribute('width')) || image.naturalWidth;
    const height = Number(image.getAttribute('height')) || image.naturalHeight;
    const bounds = image.getBoundingClientRect();
    if (width && height && bounds.width && bounds.height) return containedBounds(bounds, width, height);
    return null;
  }

  function stopMotion() {
    if (motion) {
      motion.onfinish = null;
      motion.cancel();
      motion = null;
    }
    source?.classList.remove('is-lightbox-source');
    source = null;
    dialog.classList.remove('is-zooming', 'is-closing');
  }

  function zoom(thumbnail, closing = false) {
    if (reducedMotion.matches || !image.animate) return false;
    const thumb = thumbnailBounds(thumbnail);
    const photo = photoBounds();
    if (!thumb || !photo) return false;

    const scale = thumb.width / photo.width;
    if (Math.abs(thumb.height / (photo.height * scale) - 1) > 0.01) return false;

    const x = thumb.left + thumb.width / 2 - (photo.left + photo.width / 2);
    const y = thumb.top + thumb.height / 2 - (photo.top + photo.height / 2);
    const small = { transform: `translate(${x}px, ${y}px) scale(${scale})` };
    const large = { transform: 'none' };
    const style = getComputedStyle(dialog);
    const duration = style.getPropertyValue('--duration-30').trim();
    // The production CSS may express a duration in seconds after minification.
    const durationMs = parseFloat(duration) * (duration.endsWith('ms') ? 1 : 1000);
    motion = image.animate(closing ? [large, small] : [small, large], {
      duration: durationMs,
      easing: style.getPropertyValue('--bezier-b').trim(),
      fill: 'both',
    });
    source = thumbnail;
    source.classList.add('is-lightbox-source');
    dialog.classList.add('is-zooming');
    dialog.classList.toggle('is-closing', closing);
    motion.onfinish = () => {
      if (closing) dialog.close();
      stopMotion();
    };
    return true;
  }

  async function loadFullPhoto(url, request) {
    const full = new Image();
    full.src = url;
    try {
      await full.decode();
    } catch {
      return; // Keep the thumbnail if the larger file could not be loaded.
    }
    if (request !== photoRequest) return;
    await motion?.finished.catch(() => {});
    if (request === photoRequest && !dialog.classList.contains('is-closing')) image.src = url;
  }

  function show(i) {
    if (dialog.classList.contains('is-closing')) return;
    stopMotion();
    index = (i + group.length) % group.length;
    const link = group[index];
    const thumbnail = link.querySelector('img');
    const preview = thumbnail?.complete && thumbnail.naturalWidth ? thumbnail.currentSrc : null;
    const alt = thumbnail?.alt ?? '';
    const width = link.dataset.lightboxWidth || thumbnail?.naturalWidth;
    const height = link.dataset.lightboxHeight || thumbnail?.naturalHeight;
    if (width && height) {
      image.width = width;
      image.height = height;
    } else {
      image.removeAttribute('width');
      image.removeAttribute('height');
    }

    image.src = preview || link.href;
    image.alt = alt;
    caption.textContent = alt;
    caption.hidden = !alt;

    const single = group.length < 2;
    prevButton.hidden = single;
    nextButton.hidden = single;
    counter.hidden = single;
    counter.textContent = `${index + 1} ${labels.of} ${group.length}`;

    const request = ++photoRequest;
    if (preview && preview !== link.href) loadFullPhoto(link.href, request);
    return preview ? thumbnail : null;
  }

  function open(link) {
    group = links.filter((other) => other.dataset.lightbox === link.dataset.lightbox);
    const thumbnail = show(group.indexOf(link));
    dialog.showModal();
    if (thumbnail) zoom(thumbnail);
  }

  function close() {
    if (dialog.classList.contains('is-closing')) return;
    const thumbnail = group[index].querySelector('img');
    ++photoRequest;
    if (!motion && image.complete && image.naturalWidth && zoom(thumbnail, true)) return;
    stopMotion();
    dialog.close();
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
  dialog.querySelector('.c-lightbox__button--close').addEventListener('click', close);
  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    close();
  });

  dialog.addEventListener('keydown', (event) => {
    if (group.length < 2) return;
    if (event.key === 'ArrowLeft') show(index - 1);
    if (event.key === 'ArrowRight') show(index + 1);
  });

  // A click on the backdrop or the empty space around the photo closes it.
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog || event.target.classList.contains('c-lightbox__figure')) close();
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
    if (dialog.open) return;
    stopMotion();
    image.removeAttribute('src');
  });
}
