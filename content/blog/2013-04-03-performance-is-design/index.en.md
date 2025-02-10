---
title: "Performance is design"
date: 2013-04-03
slug: "performance-is-design"
draft: false
author: "aljan"
subtitle: ""
description: "Good performance is essential for good design. Reducing page sizes, optimizing images, and minimizing code improves speed, which benefits both the user experience and the design itself."
related: ""
related_url: ""
photo: "performance-is-design.jpg"
thumbnail: ""
header: false
header_studio: false
header_title_gradient: false
---

{{<image src="performance-is-design.jpg">}}

The WiFi connection on the train is too slow, so you access a website with your slightly faster 3G connection. The website doesn’t load quickly, but you eventually get there—albeit not with much enthusiasm.

Nowadays, the average webpage is **1MB** in size, which is simply too much. We assumed that internet connections had become faster. That’s true, but we forgot about mobile connections. A 1MB page is too large, and in most cases, it’s the result of sheer laziness from designers and developers, myself included. This has changed significantly over time, and I have become aware of the importance of good performance.

Let’s revisit the example of a heavy website. In this case, each page is around 1.3MB. Imagine if it were only 250KB—five times smaller, five times faster. Cool, right? You’d be able to read an article faster, order tickets quicker, and check train schedules without having to wait 10 seconds.

Design is about ensuring that the user can achieve their goal as simply as possible. **Poor performance therefore means poor design.**

## Practical Improvements

- Develop directly in the browser, because you can’t measure performance in Photoshop.
- Pay attention to the number of images and their sizes. Use sprites or icon fonts, and don’t forget [responsive images](http://blog.cloudfour.com/8-guidelines-and-1-rule-for-responsive-images/).
- Be cautious with box-shadow; anything beyond 3 pixels can cause your website to [render more slowly](http://nerds.airbnb.com/box-shadows-are-expensive-to-paint).
- Compress and bundle all your JavaScript and CSS files.

## Further Reading

- Tutsplus: [Best practices for increasing website performance](http://webdesign.tutsplus.com/tutorials/workflow-tutorials/best-practices-for-increasing-web-site-performance/)
- Brad Frost: [Performance as design](http://bradfrostweb.com/blog/post/performance-as-design/)
- CSS Wizardry: [Front-end performance for web designers and frond-end developers](http://csswizardry.com/2013/01/front-end-performance-for-web-designers-and-frond-end-developers/)
- Clearleft: [Responsive design on a budget](http://clearleft.com/thinks/responsivedesignonabudget/)
- Smashing Magazine: [How to make your websites faster on mobile devices](http://mobile.smashingmagazine.com/2013/04/03/build-fast-loading-mobile-website/)

— This post was originally written on the Studio Wolf blog.
