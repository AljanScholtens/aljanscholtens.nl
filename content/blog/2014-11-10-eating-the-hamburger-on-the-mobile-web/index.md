---
title: "De hamburger en het mobiele web"
date: 2014-11-10
slug: "de-hamburger-en-het-mobiele-web"
draft: false
author: "aljan"

subtitle: ""
description: "Alternatieven onderzoeken voor het hamburgericoon als navigatiemiddel op mobiel, met aandacht voor problemen in iOS Safari en het voorstellen van een navigatiebalk onderaan voor betere toegankelijkheid en zichtbaarheid van website-elementen op mobiel."

related: ""
related_url: ""

photo: "eating-the-hamburger-on-the-mobile-web.jpg"
thumbnail: ""

header: true
header_studio: false
header_title_gradient: false
---

Mensen gebruiken het hamburgericoon op websites al sinds [althans de jaren negentig](http://blog.placeit.net/history-of-the-hamburger-icon/). Nu responsive websites en mobiele apps steeds populairder worden, is het gebruik van het hamburgericoon enorm toegenomen. Wij zijn op zoek naar een betere mobiele navigatie voor het web en willen graag onze ideeën en de problemen die we tegenkomen met jullie delen. Het probleem van vandaag: iOS Safari.

## Navigeren in apps

Laatst is er veel [discussie over het icoon](http://mor10.com/hamburger-bad/). Enige tijd geleden [schreef Apple ook een artikel](http://blog.manbolo.com/2014/06/30/apple-on-hamburger-menus) over het gebruik van de hamburger als navigatiemiddel.

Volgens Apple is het icoon te abstract en spreekt het gebruikers niet voldoende aan. Luke Wroblewski schreef ook een blog over de hamburger op [grote mobiele schermen](http://www.lukew.com/ff/entry.asp?1927).

Het icoon is niet erg voor de hand liggend en is op grote schermen moeilijk met één hand te bereiken.

## Hoe zit het met het mobiele web?

Veel van deze artikelen en meningen gaan over het gebruik van dit icoon in apps, maar het is belangrijk om websites niet te vergeten.

In feite kom je op het web in principe tegen dezelfde problemen:

- Je scherm kan te groot zijn, waardoor het navigatie-item bovenaan je scherm moeilijk bereikbaar is.
- Het doel van het hamburgericoon is niet duidelijk en nodigt gebruikers daardoor niet uit om het te gebruiken.
- Het gebruik van zo'n icoon verbergt bovendien standaard (het grootste deel van) de navigatie, waardoor gebruikers eerst moeten tikken of klikken om de navigatie te zien.

Een eenvoudige manier om dit probleem op te lossen is door een navigatiebalk onderaan het scherm te plaatsen. Hierdoor wordt direct zichtbaar welke andere elementen er op de website aanwezig zijn en is het menu zowel op kleine als op grote schermen gemakkelijk bereikbaar.

## Kielzog als voorbeeld

Een voorbeeld van dit gebruik is de website van Kielzog, een project waar we momenteel aan werken. Kielzog is een lokaal theater en kunstcentrum. Veel bezoekers kennen Kielzog alleen vanwege de theateruitvoeringen en zijn zich er niet van bewust dat het theater ook lessen aanbiedt in muziek, dans, theater en beeldende kunst. Met de volledige navigatie direct zichtbaar kan een gebruiker gemakkelijk door de verschillende pagina's (diensten) scannen die het theater te bieden heeft. Deze items worden vaak over het hoofd gezien wanneer de gebruiker eerst op een menu-icoon moet tikken.

Op desktop is de navigatie bovenaan de website volledig zichtbaar, maar op mobiel is dit moeilijker te realiseren. Daarom hebben we ervoor gekozen om de navigatie onderaan het scherm weer te geven wanneer de website op mobiel wordt bezocht.

Deze oplossing stelt de gebruiker in staat om gemakkelijk de andere elementen op de website te zien en maakt navigeren eenvoudig, zonder dat de hand opnieuw gepositioneerd hoeft te worden. Door de navigatie op mobiel volledig weer te geven, ziet de gebruiker meteen welke andere diensten het theater te bieden heeft.

{{<image src="eating-the-hamburger-on-the-mobile-web-1.jpg">}}

Dit klinkt als een goed alternatief voor het hamburgericoon, een alternatief dat Apple hopelijk zal goedkeuren. Klinkt allemaal goed, behalve voor één probleem. Het native gedrag van mobile Safari zorgt ervoor dat de browser zijn knoppen tevoorschijn haalt wanneer een gebruiker op een item in de navigatie tikt. Hierdoor moeten gebruikers een menu-item twee keer aanraken voordat ze naar de volgende pagina worden geleid. Twee keer tikken is natuurlijk onacceptabel. Hetzelfde geldt voor links onderaan een pagina die niet "sticky" zijn—die zijn net zo lastig al bij de eerste tik.

Samenvattend komt het er in feite op neer dat wij, de ontwikkelaars, niet in staat zijn om een gemakkelijke manier voor de gebruiker te implementeren om te navigeren via een navigatiebalk onderaan het scherm (althans, zonder dat er twee keer getikt moet worden).

Welke consensus zoeken we hier? Moeten we het hamburgericoon meer beschrijvend maken door er het woord “menu” naast te plaatsen? Of moeten we op zoek gaan naar een geheel andere implementatie voor de mobiele navigatie? Ik ben benieuwd wat Apple en jullie van dit probleem vinden.

— Dit bericht is oorspronkelijk geschreven op de Studio Wolf blog.
