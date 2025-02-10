---
title: "Hoe stel je een inline SVG-sprite in"
date: 2015-01-08
slug: "how-to-set-up-an-inline-svg-sprite"
draft: false
author: "aljan"

subtitle: ""
description: "Leer hoe je een inline SVG-sprite opzet voor schaalbare, aanpasbare iconen. Deze gids behandelt alles, van het creëren van SVG-symbolen tot het implementeren ervan op je webpagina met CSS-styling."

related: ""
related_url: ""

photo: "how-to-set-up-an-inline-svg-sprite.jpg"
thumbnail: ""

header: true
header_studio: false
header_title_gradient: false
---

Jaren geleden werkte ik met grote PNG-sprites met tal van iconen, logo's en andere afbeeldingen ter ondersteuning van de layout. Simpelweg omdat dit de meest efficiënte manier was om afbeeldingen te laden met het kleinst mogelijke aantal verbindingen. Tegenwoordig wordt SVG door alle grote browsers ondersteund. In dit artikel leg ik uit hoe je een inline SVG-sprite opzet.

Vroeger was ontwerpen voor het web lastig. We moesten sprites gebruiken voor bijna alles. Gelukkig is het web beter geworden. CSS-transformaties, border-radius, gradients en veel meer geavanceerde technieken kwamen in onze werkruimte. Nu waren alleen de iconen nog in de PNG-sprite te vinden.

## Font-iconen

Helaas veroorzaakt werken met PNG een groot probleem: PNG is geen vectorformaat. De oplossing: plaats iconen in lettertypes. Font-iconen! Font-iconen maakten me blij. Ze zijn vector en ze bespaarden me de moeite om in Photoshop sprites te maken. Maar er zit ook een nadeel aan: prestaties en flexibiliteit. Meestal gebruik ik slechts zo'n 10 iconen, maar toch moest ik een iconfont laden met 200 iconen van meer dan 100 kb. Dat voelde een beetje dom.

Tip: Met [IcoMoon](https://icomoon.io/) kun je je eigen iconfonts bouwen met minder iconen.

## Scalable Vector Graphics

Oké, laten we verder gaan met de mooie dingen. Aangezien SVG breed wordt [ondersteund door browsers](http://caniuse.com/#feat=svg) klinkt het als een goede oplossing om iconfonts te vervangen door SVG. SVG staat voor Scalable Vector Graphics en is schaalbaar, aanpasbaar en zelfs animerbaar.

Dus, hoe maak je je eigen inline SVG-sprite? Laten we beginnen!

## Een SVG-element opzetten

We plaatsen onze SVG-sprite inline in de HTML door het in een SVG-element te wikkelen.

De beste plek om een SVG-element in te voegen is direct na de openingstag `<body>`. Een SVG-element bestaat uit één of meerdere symbolen. Elk symbool is een afbeelding. Een symbool bevat een path-element. Hier komen we later nog op terug. Zorg ervoor dat je de SVG direct na `<body>` plaatst, want anders toont Safari de afbeeldingen niet.

```html
<body>

  <svg xmlns="http://www.w3.org/2000/svg">
    <symbol>
      <path />
    </symbol>
  </svg>

  <!-- Je HTML -->

</body>
```

Vergeet niet je SVG te verbergen! Als je dat niet doet, worden je afbeeldingen bovenaan je pagina getoond. Gebruik ook niet `display: none;`, want dat verbergt al je symbolen die op de pagina worden gebruikt. De beste manier is om `visibility: hidden;` te gebruiken in combinatie met `position: absolute;` en `z-index: -1;`.

Naast een inline SVG kun je ook een echt .svg-bestand gebruiken, dat gecachet kan worden! Meer hierover later.

## Maak je SVG-sprite

De eerste stap is om Illustrator of Sketch te openen en een vorm of icoon te maken/openen. Om een SVG-icoon te maken heb je twee dingen nodig:

- **Path**: paths worden gebruikt om geavanceerde vormen te tekenen, samengesteld uit lijnen, bogen, curves, etc., met of zonder vulling. Eigenlijk is het jouw afbeelding. Bekijk deze [videotutorial](https://www.youtube.com/watch?v=k6TWzfLGAKo&amp;list=PLL8woMHwr36F2tCFnWTbVBQAGQ6nTcXOO) over paths.
- **Viewbox**: als je de hele SVG-afbeelding als een canvas beschouwt, is de viewbox het deel van het canvas dat je de kijker wilt laten zien. Een uitgebreidere beschrijving van viewports is [hier](http://tutorials.jenkov.com/svg/svg-viewport-view-box.html) te vinden.

Kies in Illustrator voor ‘Opslaan als’ en selecteer ‘SVG’. Nadat je op ‘Opslaan’ hebt geklikt, krijg je de optie ‘SVG Code’. Open deze en kopieer de path(s). Als je Sketch gebruikt, kun je een nieuw artboard maken en exporteren als SVG. Open de SVG en kopieer de path(s).

```html
<path d="M8.914,5.79C9.039,5.379,9.188,4.951,9.188,4.5c0-2.485-1.983-4.5-4.469-4.5S0.234,2.015,0.234,4.5S2.257,9,4.742,9C5.193,9,5.5,8.913,5.91,8.79L6,9v2h2v2h2v2h2.25l1,1H16v-3L8.914,5.79z M3.75,5c-0.829,0-1.5-0.671-1.5-1.5S2.921,2,3.75,2s1.5,0.671,1.5,1.5S4.579,5,3.75,5z" />
```

Als al je iconen dezelfde grootte hebben, bijvoorbeeld 16×16 eenheden, dan zou je een Illustrator-bestand of Sketch artboard moeten maken met die specifieke grootte/aspect ratio. Aangezien we hier met vectorafbeeldingen werken, definiëren de eenheden alleen de beeldverhouding. 16×16 is hetzelfde als 4×4. Gebruik de eenheden in je viewbox als volgt:

```html
<symbol viewBox="0 0 16 16" />
```

## Je inline SVG samenstellen

Als we onze viewbox en path in ons inline SVG-element kopiëren, krijgen we het volgende:

```html
<svg xmlns="http://www.w3.org/2000/svg">
  <symbol viewBox="0 0 16 16" id="icon-id" preserveAspectRatio="xMinYMin">
    <path d="M8.914,5.79C9.039,5.379,9.188,4.951,9.188,4.5c0-2.485-1.983-45-4.469-4.5S0.234,2.015,0.234,4.5S2.257,9,4.742,9C5.193,9,5.5,8.913,5.91,8.79L6,9v2h2v2h2v2h2.25l1,1H16v-3L8.914,5.79zM3.75,5c-0829,0-1.5-0.671-1.5-1.5S2.921,2,3.75,2s1.5,0.671,1.5,1.5S4.579,5,3.75,5z" />
  </symbol>
</svg>
```

In dit voorbeeld bevat de SVG slechts één symbool. Voeg er meer toe om een echte sprite te creëren. Merk op dat ik twee extra zaken aan het symbool-element heb toegevoegd: de `preserveAspectRatio` om de oorspronkelijke beeldverhouding te behouden en een ID om de symbolen te identificeren zodat we ze later kunnen gebruiken.

## Laat je afbeeldingen aan de wereld zien

We hebben nu ons SVG-element, met een of meerdere symbolen erin. We kunnen nu de ID van een symbool, in dit voorbeeld `icon-id`, gebruiken en het op de webpagina invoegen met het `<use>`-element:

```html
<svg class="icon"><use xlink:href="#icon-id" /></svg>
```

Je kunt hetzelfde doen als je een extern SVG-bestand hebt; typ het dan gewoon zo:

```html
<svg class="icon"><use xlink:href="path/to/file.svg#icon-id" /></svg>
```

De laatste stap is het toevoegen van wat CSS aan de klasse `icon`, zoals hierboven gedefinieerd. Style je icoon zoals je wilt. De `fill`-eigenschap bepaalt de kleur van je symbool.

```
svg.icon {
  height: 16px;
  width: 16px;
  display: inline-block;
  fill: green;
}
```

## Samenvatting

SVG-sprites zijn een nieuwe manier om op een efficiënte manier schaalbare iconen toe te voegen aan je ontwerpen. Het maken van een SVG-sprite is niet moeilijk. Volg deze stappen en je bent op de goede weg:

- Stel de inline SVG-bestand-wrapper in;
- Maak een SVG-symbool via Illustrator of Sketch, kopieer de paths en de viewbox en plaats deze in je SVG-element;
- Voeg een symbool uit je SVG-sprite toe aan je webpagina met `<use>`;
- Style het symbool met CSS.

Werk je met [Taiga Boilerplate](https://github.com/AljanScholtens/taiga-boilerplate)? Deze aanpak is ook geïntegreerd in Taiga.

— Dit bericht is oorspronkelijk geschreven op de Studio Wolf blog.
