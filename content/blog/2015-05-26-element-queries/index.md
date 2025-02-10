---
title: "Element Queries"
date: 2015-05-26
slug: "element-queries"
draft: false
author: "aljan"

subtitle: ""
description: "Element queries stellen je in staat de inhoud van een module aan te passen op basis van de eigen breedte in plaats van de viewport, waardoor responsive design flexibeler wordt. Ze vullen media queries aan voor een betere layoutcontrole."
related: ""
related_url: ""

photo: "element-queries.jpg"
thumbnail: ""

header: false
header_studio: false
header_title_gradient: false
---

{{< photos >}}
element-queries.jpg |  | 100
{{< /photos >}}

Al een paar jaar maken we responsive websites. We doen dit met de beroemde media queries, en die zijn geweldig. Maar wat als we het wat slimmer konden aanpakken, bijvoorbeeld met element queries? Laten we eerst eens kijken naar onze huidige methode: media queries.

## De media query

Een media query kan een breakpoint definiëren. Op een breakpoint kunnen we ons design aanpassen. Bij responsive design werken we meestal met aanpassingen in hoogte en/of breedte op basis van de viewport van de browser.

Stel dat we willen dat de pagina verandert bij een minimale viewportbreedte van 700 pixels. Meestal doen we dit (de code is in SCSS):

```
.product-list li {
  float: left;
  width: 50%;
  padding: 10px;

  @media (min-width: 1000px) {
    width: 25%;
    padding: 20px;
  }
}
```

We gebruiken een module genaamd `.product-list`. Op mobiel staat de productlijst met twee items naast elkaar. Wanneer de breedte van de browser 1000 pixels bereikt, plaatsen we vier items naast elkaar en vergroten we de padding. Geweldig!

{{<image src="element-queries-1.gif">}}

Maar dat gebeurt wanneer we onze productlijst in een kleine zijbalk plaatsen. De items blijven dan naast elkaar staan, wat er niet altijd mooi uitziet.

## Element queries

Met element queries kunnen we de inhoud van een module aanpassen op basis van de breedte van de module zelf, in plaats van op basis van de viewport zoals bij media queries.

{{<image src="element-queries-2.gif">}}

Laten we teruggaan naar de productlijst. Wanneer de module breder is dan 1000 pixels, willen we dat de items een breedte van 25% en meer padding krijgen – niet wanneer de viewport groter wordt dan 1000 pixels. (De code is in SCSS):

```
.product-list li {
  float: left;
  width: 50%;
  padding: 10px;
}

.product-list[min-width: 1000px] li {
  width: 25%;
  padding: 20px;
}
```


Is dat niet fantastisch? We kunnen onze modules nu zo bouwen dat ze **zich aanpassen aan hun eigen breedte en overal geplaatst kunnen worden** – in de hoofdcontent, in de zijbalk, of waar dan ook. Vooral voor grotere webapplicaties is dit erg handig, omdat sommige modules in panelen van verschillende groottes moeten passen.

Element queries dienen gecombineerd te worden met media queries. Gebruik media queries voor de layoutregels of het grid, en element queries voor de individuele modules en componenten. Anders kan het een hele uitdaging worden om kolommen netjes naast elkaar te krijgen en kan de layout uit elkaar vallen. Ze zijn dus eigenlijk goede vrienden en geen concurrenten.

## Browserondersteuning & polyfill

Er is geen native ondersteuning; het werkt alleen met een beetje JavaScript. Voorlopig kun je de [polyfill](https://github.com/marcj/css-element-queries) van [Marc Schmidt](https://twitter.com/MarcJSchmidt) gebruiken.

## De toekomst

Ik hoop dat element queries binnen enkele jaren in native CSS zullen werken. Er zijn echter wel enkele uitdagingen. Meer hierover lees je in de volgende artikelen:

- [http://www.xanthir.com/b4VG0](http://www.xanthir.com/b4VG0)
- [http://www.filamentgroup.com/lab/element-query-workarounds.html](http://www.filamentgroup.com/lab/element-query-workarounds.html)
- [https://responsiveimagescg.github.io/eq-usecases/](https://responsiveimagescg.github.io/eq-usecases/)

— Dit bericht is oorspronkelijk geschreven op de Studio Wolf blog.
