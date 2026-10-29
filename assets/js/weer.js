// Renders the three-day photo weather page from /weer-data.php.
(function () {
  var WEEKDAYS = { ma: 'maandag', di: 'dinsdag', wo: 'woensdag', do: 'donderdag',
    vr: 'vrijdag', za: 'zaterdag', zo: 'zondag' };
  var daysEl = document.querySelector('[data-days]');
  var updatedEl = document.querySelector('[data-updated]');

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
    if (day.local_mist) rows.appendChild(row('Lokale mist', 'mogelijk'));
    if (day.rime) rows.appendChild(row('Rijp', day.rime.percent + '%', 'min ' + temperature(day.rime.min_temp)));
    if (day.dew) rows.appendChild(row('Dauw', day.dew.label, 'min ' + temperature(day.dew.min_temp)));
    if (day.calm) rows.appendChild(row('Windstil', 'spiegelingen mogelijk'));
    if (day.fresh_snow_cm) rows.appendChild(row('Verse sneeuw', 'ongeveer ' + day.fresh_snow_cm + ' cm'));
    if (day.snow_percent) rows.appendChild(row('Sneeuw', day.snow_percent + '%'));
    if (day.color) rows.appendChild(row('Kleur in de lucht', day.color));
    rows.appendChild(row('Ochtendzon', day.sun));
    rows.appendChild(row('Weer', day.weather));
    card.appendChild(rows);
    return card;
  }

  function setPlace(name) {
    var isDefault = !name || name === 'Vries';
    document.title = 'Fotoweer' + (isDefault ? '' : ' ' + name) + ' - Aljan Scholtens';
    // Show the place as found, not the query behind a choice ("Landgraaf, Landgraaf").
    searchInput.value = name || 'Vries';
  }

  function showMessage(text, choices) {
    daysEl.textContent = '';
    updatedEl.textContent = '';
    var box = el('div', 'w-status');
    box.appendChild(el('p', null, text));
    if (choices && choices.length) {
      var list = el('div', 'w-choices');
      choices.forEach(function (choice) {
        // A choice is a plain place name or a {label, query} pair.
        var button = el('button', 'w-choice', choice.label || choice);
        button.type = 'button';
        button.addEventListener('click', function () {
          search(choice.query || choice);
        });
        list.appendChild(button);
      });
      box.appendChild(list);
    }
    daysEl.appendChild(box);
  }

  function render(data) {
    var now = Date.now();
    // Today drops out as soon as its sunrise has passed.
    var days = (data.days || []).filter(function (day) {
      return Date.parse(day.sunrise_at) > now;
    }).slice(0, 3);
    setPlace(data.place);
    if (!days.length) {
      showMessage('Er is nu geen verwachting beschikbaar.');
      return;
    }
    daysEl.textContent = '';
    days.forEach(function (day) {
      daysEl.appendChild(dayCard(day));
    });
    var updated = new Date(data.generated_at);
    updatedEl.textContent = 'Bijgewerkt om ' + updated.toLocaleTimeString('nl-NL',
      { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Amsterdam' })
      + ' · wordt elke twee uur opnieuw berekend';
  }

  var request = 0;

  function load(query) {
    var current = ++request;
    searchInput.value = query || 'Vries';
    showMessage(query ? 'Verwachting voor ' + query + ' berekenen…' : 'Verwachting laden…');
    fetch('/weer-data.php' + (query ? '?plaats=' + encodeURIComponent(query) : ''),
      { headers: { Accept: 'application/json' } })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        // A slower, older search must not overwrite a newer one.
        if (current !== request) return;
        if (data.days) {
          render(data);
        } else if (data.status === 'choose') {
          showMessage('Er zijn meerdere plaatsen met deze naam. Welke bedoel je?', data.places);
        } else if (data.status === 'not_found') {
          showMessage(data.message + (data.suggestions && data.suggestions.length
            ? ' Bedoel je een van deze?' : ''), data.suggestions);
        } else {
          showMessage(data.message || 'De verwachting kan nu niet worden geladen.');
        }
      })
      .catch(function () {
        if (current !== request) return;
        showMessage('De verwachting kan nu niet worden geladen. Probeer het later opnieuw.');
      });
  }

  function search(query) {
    // Vries is the default page, so it keeps the plain address.
    if (query.toLowerCase() === 'vries') query = '';
    history.pushState(null, '', query ? '/weer/?plaats=' + encodeURIComponent(query) : '/weer/');
    load(query);
  }

  function queryFromUrl() {
    return (new URLSearchParams(location.search).get('plaats') || '').trim();
  }

  var searchForm = document.querySelector('[data-search]');
  var searchInput = searchForm.querySelector('input');
  searchForm.addEventListener('submit', function (event) {
    event.preventDefault();
    search(searchInput.value.trim());
    searchInput.blur();
  });
  // The field holds the current place; one tap selects it, so typing replaces it.
  searchInput.addEventListener('focus', function () {
    // After the click has placed the cursor, otherwise the browser undoes the selection.
    setTimeout(function () {
      searchInput.select();
    }, 0);
  });
  window.addEventListener('popstate', function () {
    load(queryFromUrl());
  });

  window.fotoweerRender = render;
  window.fotoweerMessage = showMessage;
  load(queryFromUrl());
})();
