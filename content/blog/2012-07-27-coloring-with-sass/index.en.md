---
title: "Coloring with Sass"
date: 2012-07-27
slug: "coloring-with-sass"
draft: false
author: "aljan"
subtitle: ""
description: ""
related: ""
related_url: ""
photo: ""
thumbnail: ""
header: false
header_studio: false
header_title_gradient: false
aliases:
  - /blog/coloring-with-sass/
---

When we color our designs with CSS, a very convenient way to achieve transparency is the alpha channel using the `rgba()` function. This function requires four parameters: a red, green and blue value and an alpha value. For example: `rgba(78, 85, 58, 0.5)` where we generate a Studio Wolf green color with a 50% transparency.

The only thing is: you never work with RGB values on the web. You work with hexadecimal colors. Fortunately for us, we have Sass. Sass automatically transforms your hex code to a valid RGB input. We can use the `rgba()` function in Sass like this: `rgba(#4e553a, 0.5)`. Sass will then transform this to `rgba(78, 85, 58, 0.5)` for us. And we all live happily ever after.

Sass also offers functions to lighten and darken colors without changing the hex codes. The `lighten()` and `darken()` functions both have two parameters. The first one is your hex code and the second one is the percentage you want to lighten or darken your color. For example: `lighten(#4e553a, 10%);`.

By using these Sass functions it’s easy to change the colors within your project without the need to dive into an extra tool to find out RGB values or look up different hex colors. Check out the following example:

```
$brand-color: green;

a.button {
  background: lighten($brand-color, 10%);
  border: 1px solid darken($brand-color, 25%);
  box-shadow: 1px 1px 3px rgba($brand-color, 0.2);
}
```

Tip: try `hsla()` instead of `rgba()` to quickly change hue or saturation.

It's possible to combine the functions `rgba()`, `lighten()` and `darken()`. Combine with caution, as it can get cluttered pretty quickly.

— This post was originally written on the Studio Wolf blog.