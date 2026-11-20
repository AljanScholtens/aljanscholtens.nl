# Afspraken voor deze site

Persoonlijke site van Aljan Scholtens: Hugo, handgeschreven CSS en een beetje
vanilla JavaScript. Doel: strak en klein houden. Geen wildgroei.

## Eerst vragen, dan doen

Stel het voor en wacht op een akkoord voordat je:

- een **nieuw token** toevoegt (kleur, maat, ruimte, regelafstand);
- een **nieuw component** of een nieuwe variant (`--modifier`) maakt;
- een **library, plugin, npm-pakket of externe dienst** toevoegt;
- het **ontwerp** verandert: kleuren, lettertypes, afstanden, layout, iconen;
- **teksten** schrijft of herschrijft die niet gevraagd zijn;
- iets **verwijdert** dat in gebruik lijkt (pagina's, content, bestanden);
- buiten de vraag **opruimt of refactort**. Zie je iets, meld het, en laat het staan.

Commit, push en deploy alleen als daarom gevraagd wordt.

## CSS: ITCSS + BEM + tokens

- **Lagen** (volgorde in `assets/css/styles.css`): tokens → base → layout →
  components → utilities. Een nieuw bestand komt in de juiste laag en wordt
  daar geïmporteerd.
- **Eén component, één bestand:** `components.<naam>.css`.
- **Naamgeving:** BEM met namespace: `c-` component, `l-` layout,
  `u-` utility, `t-` thema, `is-` toestand. Bijvoorbeeld `c-card__title`,
  `c-card--small`. Geen losse classnamen zonder namespace.
- **Alleen tokens** uit `assets/css/tokens.css` voor kleur, lettergrootte,
  ruimte en regelafstand. Geen losse waarden zoals `18px` of `#333`. Past
  geen bestaand token, vraag dan (zie boven).
- **Dark mode via rol-tokens** (`--color-text`, `--color-text-muted`,
  `--color-line`), die `.t-dark` omzet. Geen nieuwe `.t-dark .iets`-regels
  als een rol-token het kan oplossen.
- **Breakpoints:** alleen deze vier, uitgeschreven:
  `600px`, `750px`, `1000px`, `1200px` (`min-width`).
- **Eerst hergebruiken:** kijk of een bestaand component of token het al doet
  voordat je iets nieuws voorstelt.
- Native CSS is genoeg: nesting en custom properties. Geen PostCSS, geen Sass,
  geen Tailwind.

## Templates en content

- **Vaste teksten** in templates gaan via `i18n/nl.toml` en `i18n/en.toml`
  (`{{ T "sleutel" }}`), nooit via `if eq .Lang "nl"`.
- **Foto's** lopen via de bestaande partials: `lightbox-photo.html` (galerijen),
  `header-image.html` (headers), `functions/photo.html` (een foto opzoeken).
  Geen originele, onbewerkte foto's op pagina's.
- **Metadata en structured data** staan in `head-meta.html` en
  `structured-data.html`. Daar aanpassen, niet per template.
- **Engelse About-pagina:** alleen `index.en.md`, daarom heten de foto's
  `*.en.jpg`. Komt er een Nederlandse versie, haal dan de `.en` weg.

## JavaScript

- Vanilla, in `assets/js/`, gebundeld door Hugo's `js.Build` via `site.js`.
- Geen jQuery, geen frameworks, geen npm.

## Hugo

- Minimaal Hugo 0.170 (vastgelegd in `hugo.toml`).
- De build moet **nul waarschuwingen** geven, ook met `--logLevel info`.
- Na een wijziging aan `hugo.toml` of `assets/css/styles.css`: dev-server
  herstarten.

## Controleren voor je zegt dat het klaar is

- Build zonder waarschuwingen.
- In de browser bekeken: desktop én mobiel, licht én donker.
- Geen kapotte interne links.

## Niet aankomen zonder overleg

- De fotoweerbot: `static/fog-*.php`, `static/weer-data.php`, `ops/`,
  `deploy-fog.sh`. Die heeft een eigen werkwijze (zie `README`).
- `static/.htaccess`: de www-redirect slaat `.php` bewust over, want de
  Telegram-webhook mag niet doorverwezen worden.
