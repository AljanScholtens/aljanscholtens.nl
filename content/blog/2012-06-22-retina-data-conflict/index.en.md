---
title: "Retina data conflict"
date: 2012-06-22
slug: "retina-data-conflict"
draft: false
author: "aljan"
subtitle: ""
description: "Retina screens offer stunning resolution, but increase file sizes and affect mobile data usage. Solutions like responsive images and media queries help, but there’s still no perfect solution for data consumption."
related: ""
related_url: ""
photo: "retina-data-conflict.jpg"
thumbnail: ""
header: false
header_studio: false
header_title_gradient: false
---

{{<image src="retina-data-conflict.jpg">}}

The Retina display: a screen with four times as many pixels in the same area, now available for iPad and, since last week, the MacBook Pro. Text and images look absolutely stunning on these screens, but not everyone is ready for it yet. Interface designers need to consider several factors.

## Mobile Internet

Four times as many pixels mean that images in a web application become twice as large in terms of dimensions. An image that’s twice as large has a significantly bigger file size. Where a photo might normally be around 100 kilobytes, for Retina it becomes about three to four times bigger. This is a big impact on your mobile data, especially in places like the Netherlands where mobile data plans are limited.

This also affects speed, and therefore the user experience.

## Penalty from Google

Google also prefers fast websites. The question is: to what extent will Google penalize websites for optimizing for the Retina display?

## What now?

[Using responsive images](https://www.smashingmagazine.com/2012/06/responsive-images-with-wordress-featured-images/) is a good solution to load smaller images on devices with a lower resolution. Of course, this won’t apply to Retina displays, because they work with a different pixel density.

Solutions for addressing Retina screens can easily be implemented with [media queries](http://seesparkbox.com/foundry/targeting_iphone_4s_retina_display_with_media_queries) in CSS, but users with these screens will still face higher data usage.

Another possible solution for the data issue could be to detect the type of data connection that’s available. For instance, you could serve lower-quality images if the network is poor. Unfortunately, there’s no solid solution for that yet.

## Conclusion

Mobile and regular data providers will have to adjust their plans to accommodate these refined displays, because eventually my Cinema Display will also be Retina. As for Google, it will likely judge the most minimal version of a website, meaning that if you optimize correctly, you won’t be penalized.

Ultimately, all devices will move toward higher pixel densities in the coming years. Apple has kicked it off, and the rest will follow with a more readable web experience.

— This post was originally written on the Studio Wolf blog.
