---
title: "Light, lighter, lightest, lightestest"
date: 2014-07-04
slug: "light-lighter-lightest-lightestest"
draft: false
author: "aljan"

subtitle: ""
description: ""

related: ""
related_url: ""

photo: "light-lighter-lightest-lightestest.jpg"
thumbnail: ""

header: true
header_studio: false
header_title_gradient: false
---

Kleurvariabelen in Sass of less kunnen erg lastig zijn om te onderhouden en uit te breiden. In deze note vertel ik de problemen waar wij tegen aan zijn gelopen en hoe we dat opgelost hebben.

Ik vind het werken met variabelen belangrijk, omdat hiermee de **consistentie binnen een web-applicatie** drastisch wordt verbeterd. Met name wanneer je met twee of meer designers in een team werkt. De één gebruikt bijvoorbeeld `lighten($gray-light, 10%)` en de ander `>lighten($gray-light, 7%)`. Op deze manier wordt alles net een beetje anders.

## Onhandige Sass variabelen

In Twitter Bootstrap heb je bijvoorbeeld [standaard kleurvariabelen](http://getbootstrap.com/css/#less-variables) als `$gray-light`, `$gray-lighter` etc. Maar wat doe je als je een grijswaarde vaak gebruikt die daar precies tussen in zit zonder dat je `lighten()` of `darken()` toepast? Dan krijg je iets als `$gray-light-less` of `$gray-light-2`. Ik moet hierbij denken aan de middelbare school waarbij ik verslagen maakte met de bestandsnamen ‘verslag-nieuw.doc’, ‘verslag-final.doc’ en verslag-FINALFINAL.doc’. Vreselijk. Dat veranderde later snel naar versienummers, v1, v2, v33 enzovoort.

## CSS Variabelen met een systeem

Waarom werken we met Sass variabelen ook niet met een soort van **versienummer-systeem**? Dan krijgen we het volgende:

```
$color-neutral-10: #eee;
$color-neutral-30: #bbb;
$color-neutral-50: #fff;
$color-neutral-70: #444;
$color-neutral-90: #111;
```

Hier gebruiken we trouwens `$color-neutral` in plaats van `$color-gray`, omdat de grijze tint binnen een applicatie niet altijd letterlijk grijs hoeft te zijn, maar het kan ook grijs-blauwig zijn.

Dit systeem is ook breder toepasbaar, met bijvoorbeeld border-radius.

```
$border-radius-10: 0.25rem;
$border-radius-20: 0.75rem;
```

## Voordelen

Zo’n systeem heeft een aantal voordelen.

- Doordat we in stappen van tien werken, kunnen we altijd een extra variabele toevoegen, bijvoorbeeld `$color-neutral-35`. Je behoudt dus je creativiteit en vrijheid.
- Met kleuren is de `$color-neutral-0` volledig wit en `$color-neutral-100` volledig zwart. Zo kun je heel eenvoudig de verschillende tinten daar binnen verdelen.
- Voor alle variabelen binnen dit systeem geldt, je gaat van **niets naar iets**. Van wit naar zwart, en met border-radius en font-size van klein naar groot.
- Misbruik een variabele niet door deze binnen een module in `lighten()` of `darken()` te zetten, zo ontstaat er alsnog inconsistentie.
- Dit systeem geef je een standaard set van variabelen en je wordt geforceerd om eerst met deze variabelen te werken zonder dat je direct een andere kleur gaat toevoegen.

## Wrap-up

Binnen Taiga werken we met bovenstaand variabelen-systeem. Het heeft weinig beperkingen, maar zorgt wel voor een sterkere consistentie binnen een web-applicatie. Het is wel even wennen met een systeem die op een andere manier variabelen aanlevert, maar na één project gaat het al stukken beter. Laat graag jouw oplossing of idee achter in de reacties!

Wil je het gebruiken? Bovenstaand variabelen-systeem zit in de [master versie](https://github.com/aljanscholtens/taiga-boilerplate) van Taiga boilerplate.

— This post was originally written on the Studio Wolf blog.
