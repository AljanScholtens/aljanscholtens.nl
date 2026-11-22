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
  let stopZoom = () => {};

  function photoBounds() {
    const width = Number(image.getAttribute('width')) || image.naturalWidth;
    const height = Number(image.getAttribute('height')) || image.naturalHeight;
    const bounds = image.getBoundingClientRect();
    if (width && height && bounds.width && bounds.height) return containedBounds(bounds, width, height);
    return null;
  }

  function zoom(thumbnail, from, to, onFinish) {
    const preview = new Image();
    preview.src = onFinish && image.complete && image.naturalWidth ? image.currentSrc : thumbnail.currentSrc || thumbnail.src;
    preview.alt = '';
    preview.className = 'c-lightbox__zoom';
    preview.setAttribute('aria-hidden', 'true');
    preview.style.objectPosition = getComputedStyle(thumbnail).objectPosition;
    dialog.append(preview);
    dialog.classList.add('is-zooming');

    const style = getComputedStyle(dialog);
    const box = ({ left, top, width, height }) => ({
      left: `${left}px`, top: `${top}px`, width: `${width}px`, height: `${height}px`,
    });
    const sameRatio = Math.abs((from.width * to.height) / (from.height * to.width) - 1) < 0.01;
    let frames = [box(from), box(to)];
    if (sameRatio) {
      // Move a single layer instead of resizing the image on every frame.
      const base = onFinish ? from : to;
      Object.assign(preview.style, box(base));
      frames = [from, to].map(({ left, top, width, height }) => ({
        transform: `translate(${left - base.left}px, ${top - base.top}px) scale(${width / base.width}, ${height / base.height})`,
      }));
    }
    const animation = preview.animate(frames, {
      duration: parseFloat(style.getPropertyValue('--duration-30')),
      easing: style.getPropertyValue('--bezier-default').trim(),
      fill: 'both',
    });
    const hideThumbnail = preview.complete && preview.naturalWidth;
    if (hideThumbnail) thumbnail.classList.add('is-lightbox-source');
    let active = true;

    function cleanup() {
      active = false;
      if (hideThumbnail) thumbnail.classList.remove('is-lightbox-source');
      preview.remove();
      dialog.classList.remove('is-zooming');
      stopZoom = () => {};
    }

    function finish() {
      cleanup();
      onFinish?.();
    }

    animation.onfinish = async () => {
      if (!onFinish) await image.decode().catch(() => {});
      if (!active) return;
      if (!onFinish) dialog.classList.remove('is-loading');
      finish();
    };
    stopZoom = () => {
      const bounds = preview.getBoundingClientRect();
      animation.onfinish = null;
      animation.cancel();
      cleanup();
      return bounds;
    };
  }

  function preload(i) {
    const link = group[i];
    if (link) new Image().src = link.href;
  }

  function show(i) {
    if (dialog.classList.contains('is-closing')) return;
    stopZoom();
    index = (i + group.length) % group.length;
    const link = group[index];
    const thumbnail = link.querySelector('img');
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

    dialog.classList.add('is-loading');
    image.onload = image.onerror = () => dialog.classList.remove('is-loading');
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
    const thumbnail = link.querySelector('img');
    const from = thumbnailBounds(thumbnail);
    dialog.classList.remove('is-closing');
    group = links.filter((other) => other.dataset.lightbox === link.dataset.lightbox);
    show(group.indexOf(link));
    dialog.showModal();
    if (!thumbnail || reducedMotion.matches) return;

    // The dialog and a newly selected image may need a layout pass after showModal.
    dialog.classList.add('is-zooming');
    const frame = requestAnimationFrame(() => {
      stopZoom();
      const start = from || thumbnailBounds(thumbnail);
      const end = start && photoBounds();
      if (end) zoom(thumbnail, start, end);
    });
    stopZoom = () => {
      cancelAnimationFrame(frame);
      dialog.classList.remove('is-zooming');
      stopZoom = () => {};
    };
  }

  function close() {
    if (dialog.classList.contains('is-closing')) return;
    const zoomBounds = stopZoom();
    const thumbnail = group[index].querySelector('img');
    const to = !reducedMotion.matches && thumbnailBounds(thumbnail);
    const from = to && (zoomBounds || photoBounds());
    if (!from) {
      dialog.close();
      return;
    }

    dialog.classList.add('is-closing');
    zoom(thumbnail, from, to, () => dialog.close());
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
    stopZoom();
    image.removeAttribute('src');
  });
}
