// Renders the three-day photo weather page from /weer-data.php.
(function () {
  var WEEKDAYS = { ma: 'maandag', di: 'dinsdag', wo: 'woensdag', do: 'donderdag',
    vr: 'vrijdag', za: 'zaterdag', zo: 'zondag' };
  var daysEl = document.querySelector('[data-days]');
  var updatedEl = document.querySelector('[data-updated]');
  var placeEl = document.querySelector('[data-place]');

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function localDate(offset) {
    var date = new Date();
    date.setDate(date.getDate() + offset);
    return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0')
      + '-' + String(date.getDate()).padStart(2, '0');
  }

  function temperature(value) {
    return String(value).replace('-', '−') + '°';
  }

  function row(label, value, note) {
    var item = el('li', 'w-row');
    item.appendChild(el('span', 'w-row__label', label));
    var valueEl = el('span', 'w-row__value', value);
    if (note) valueEl.appendChild(el('span', 'w-row__note', note));
    item.appendChild(valueEl);
    return item;
  }

  function dayCard(day) {
    var card = el('article', 'w-day' + (day.advice ? ' w-day--' + day.advice : ''));
    var top = el('div', 'w-day__top');
    var heading = el('h2', 'w-day__date');
    var relative = day.date === localDate(0) ? 'Vanochtend'
      : day.date === localDate(1) ? 'Morgen' : WEEKDAYS[day.weekday];
    heading.appendChild(el('span', 'w-day__weekday', relative));
    heading.appendChild(el('span', 'w-day__date-sub',
      (relative === WEEKDAYS[day.weekday] ? '' : day.weekday + ' ') + day.day_label));
    top.appendChild(heading);
    if (day.uncertain) {
      top.appendChild(el('span', 'w-badge', 'Onzeker'));
    } else if (day.preliminary) {
      top.appendChild(el('span', 'w-badge', 'Voorlopig'));
    }
    card.appendChild(top);

    if (day.error) {
      card.appendChild(el('p', 'w-day__error', day.error));
      return card;
    }

    var verdict = el('div', 'w-verdict');
    var score = el('div', 'w-score');
    score.style.setProperty('--w-score', day.score);
    score.setAttribute('aria-label', 'Cijfer ' + day.score + ' van 10');
    var scoreText = el('div');
    scoreText.appendChild(el('span', 'w-score__value', day.score));
    scoreText.appendChild(el('span', 'w-score__max', '/10'));
    score.appendChild(scoreText);
    verdict.appendChild(score);
    var advice = el('div');
    advice.appendChild(el('p', 'w-advice', day.advice_label));
    var wake = el('p', 'w-wake');
    if (day.wake) {
      wake.appendChild(document.createTextNode('Wekker '));
      wake.appendChild(el('strong', null, day.wake));
    } else {
      wake.textContent = 'Geen wekker nodig';
    }
    advice.appendChild(wake);
    verdict.appendChild(advice);
    card.appendChild(verdict);
    card.appendChild(el('p', 'w-reason', day.reason));

    var light = el('div', 'w-light');
    var first = el('div', 'w-light__point');
    first.appendChild(el('strong', null, day.first_light));
    first.appendChild(document.createTextNode('Eerste licht'));
    var sun = el('div', 'w-light__point w-light__point--sun');
    sun.appendChild(el('strong', null, day.sunrise));
    sun.appendChild(document.createTextNode('Zon op'));
    light.appendChild(first);
    light.appendChild(el('span', 'w-light__line'));
    light.appendChild(sun);
    card.appendChild(light);

    var rows = el('ul', 'w-rows');
    if (day.mist) {
      var notes = [];
      if (day.mist.dense_percent) notes.push('dicht ' + day.mist.dense_percent + '%');
      if (day.mist.best_hour) notes.push('rond ' + day.mist.best_hour);
      rows.appendChild(row('Mist', day.mist.percent + '%', notes.join(' · ')));
    }
    if (day.ground) rows.appendChild(row('Grondmist', 'gunstig'));
    if (day.forest) rows.appendChild(row('Bosmist', 'gunstig'));
    if (day.rime) rows.appendChild(row('Rijp', day.rime.percent + '%', 'min ' + temperature(day.rime.min_temp)));
    if (day.dew) rows.appendChild(row('Dauw', day.dew.percent + '%', 'min ' + temperature(day.dew.min_temp)));
    if (day.calm) rows.appendChild(row('Windstil', 'spiegelingen mogelijk'));
    if (day.snow_percent) rows.appendChild(row('Sneeuw', day.snow_percent + '%'));
    rows.appendChild(row('Ochtendzon', day.sun_percent + '%'));
    rows.appendChild(row('Weer', day.weather));
    card.appendChild(rows);
    return card;
  }

  function render(data) {
    var now = Date.now();
    // Today drops out as soon as its sunrise has passed.
    var days = (data.days || []).filter(function (day) {
      return Date.parse(day.sunrise_at) > now;
    }).slice(0, 3);
    daysEl.textContent = '';
    if (!days.length) {
      daysEl.appendChild(el('p', 'w-status', 'Er is nu geen verwachting beschikbaar.'));
      return;
    }
    days.forEach(function (day) {
      daysEl.appendChild(dayCard(day));
    });
    placeEl.textContent = data.place;
    var updated = new Date(data.generated_at);
    updatedEl.textContent = 'Bijgewerkt om ' + updated.toLocaleTimeString('nl-NL',
      { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Amsterdam' })
      + ' · wordt elke twee uur opnieuw berekend';
  }

  window.fotoweerRender = render;

  fetch('/weer-data.php', { headers: { Accept: 'application/json' } })
    .then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    })
    .then(render)
    .catch(function () {
      daysEl.textContent = '';
      daysEl.appendChild(el('p', 'w-status', 'De verwachting kan nu niet worden geladen. Probeer het later opnieuw.'));
    });
})();
