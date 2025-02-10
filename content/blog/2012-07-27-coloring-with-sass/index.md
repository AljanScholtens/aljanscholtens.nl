---
title: "Kleuren met Sass"
date: 2012-07-27
slug: "kleuren-met-sass"
draft: false
author: "aljan"
subtitle: ""
description: "Met Sass maak je werken met transparantie en kleuren eenvoudig. De rgba()-functie werkt met hex-codes, en met functies als lighten() en darken() pas je kleuren snel aan zonder handmatig te rekenen."
related: ""
related_url: ""
photo: ""
thumbnail: ""
header: false
header_studio: false
header_title_gradient: false
---

Als we onze designs kleuren met CSS, is een heel handige manier om transparantie te bereiken het gebruik van het alfakanaal via de `rgba()`-functie. Deze functie verwacht vier parameters: een waarde voor rood, groen en blauw, en een alfawaarde. Bijvoorbeeld: `rgba(78, 85, 58, 0.5)`, waarmee we een Studio Wolf-groene kleur met 50% transparantie genereren.

Het enige is: je werkt eigenlijk nooit met RGB-waarden op het web, maar met hexadecimale kleuren. Gelukkig hebben we Sass. Sass zet je hexcode automatisch om naar een geldige RGB-invoer. We kunnen de `rgba()`-functie in Sass dan bijvoorbeeld zo gebruiken: `rgba(#4e553a, 0.5)`. Sass zet dit vervolgens om naar `rgba(78, 85, 58, 0.5)`. En iedereen leeft nog lang en gelukkig.

Sass biedt ook functies om kleuren lichter of donkerder te maken zonder dat je zelf hexcodes hoeft aan te passen. De functies `lighten()` en `darken()` hebben allebei twee parameters. De eerste is je hexcode en de tweede is het percentage waarmee je de kleur wilt oplichten of verdonkeren. Bijvoorbeeld: `lighten(#4e553a, 10%);`.

Door deze Sass-functies te gebruiken kun je makkelijk kleuren aanpassen binnen je project, zonder dat je een extra tool hoeft te raadplegen voor RGB-waarden of andere hexkleuren. Bekijk onderstaand voorbeeld:

```
$brand-color: green;

a.button {
  background: lighten($brand-color, 10%);
  border: 1px solid darken($brand-color, 25%);
  box-shadow: 1px 1px 3px rgba($brand-color, 0.2);
}
```

Tip: probeer `hsla()` in plaats van `rgba()` om snel de tint (hue) of verzadiging (saturation) te veranderen.

Je kunt de functies `rgba()`, `lighten()` en `darken()` ook combineren. Doe dit wel met beleid, want het kan snel onoverzichtelijk worden.

— Deze post is oorspronkelijk geschreven op de Studio Wolf-blog.
