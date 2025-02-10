---
title: "Sass Placeholders"
date: 2012-10-23
slug: "sass-placeholders"
draft: false
author: "aljan"
subtitle: ""
description: "Sass Placeholders helpen bij het verminderen van herhalingen in CSS door gebruik te maken van %selector. Via @extend kunnen we gemeenschappelijke stijlen toepassen zonder duplicatie in de output."
related: ""
related_url: ""
photo: "sass-placeholders.jpg"
thumbnail: ""
header: false
header_studio: false
header_title_gradient: false
---

{{<image src="sass-placeholders.jpg">}}

Een veel voorkomend probleem met HTML is het aantal classes dat we gebruiken, zoals bijvoorbeeld `<div class="product product-hat">`. Ook het dupliceren van CSS gebeurt te vaak om het gewenste resultaat te krijgen. Er is een oplossing.

Tijdens de [Smashing Conference](http://smashingconf.com/) in Freiburg vertelde Nicole Sullivan ([@Stubornella](https://twitter.com/stubbornella)) over de [SASS placeholder](http://sass-lang.com/docs/yardoc/file.SASS_REFERENCE.html#placeholder_selectors_) (werkt vanaf SASS 3.2). De placeholder is een type selector die niet in de CSS output komt na compilen.

Waarbij we normaal gesproken `.selector` of `#selector` gebruiken, pakken we nu de `%selector`. Aan onderstaand `%product` heb je niets; het komt namelijk niet in de CSS output.

```
%product {
  background: grey;
  border: 1px solid;
  padding: 1em;
}
```

Maar we kunnen er wel degelijk iets mee. Het voordeel zit hem in de combinatie met [@extend](http://sass-lang.com/docs/yardoc/file.SASS_REFERENCE.html#extend).

In CSS gebruiken we bijvoorbeeld `div.product`. Stel, we verkopen schoenen en hoedjes via een webshop. In het productoverzicht hebben alle producten een aantal dezelfde eigenschappen zoals background, border en padding. Alleen verschillen de schoenen en hoedjes in borderkleur middels de volgende SCSS:

```
%product {
  background: grey;
  border: 1px solid;
  padding: 1em;
}

div.product-shoe {
  @extend %product;
  border-color: red;
}

div.product-hat {
  @extend %product;
  border-color: blue;
}
```

*Tip: De `@extend` wordt altijd als laatste binnen de selector ingeladen, dus overschrijven kan lastig zijn.*

`%product` komt op deze manier niet in de output, maar de schoen en de hoed kunnen wel gebruik maken van de eigenschappen van `%product`. Daarmee krijg je de output:

```
div.product-shoe, div.product-hat {
  background: grey;
  border: 1px solid;
  padding: 1em;
}

div.product-shoe {
  border-color: red;
}

div.product-hat {
  border-color: blue;
}
```

Hiermee wordt de HTML simpeler en **semantischer**; je krijgt geen `<div class="product product-hat">`, maar alleen `<div class="product-hat">`.

Daarnaast hoef je in de SCSS geen code te dupliceren voor zowel `.product-shoe` als `.product-hat`. **Minder code.**

Als interface designer zorgt dit voor een beter te onderhouden framework en voor de bezoeker voor betere performance.

— This post was originally written on the Studio Wolf blog.