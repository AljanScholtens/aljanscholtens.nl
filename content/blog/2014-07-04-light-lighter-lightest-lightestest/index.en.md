---
title: "Light, lighter, lightest, lightestest"
date: 2014-07-04
slug: "light-lighter-lightest-lightestest"
draft: false
author: "aljan"
subtitle: ""
description: "A systematic approach to Sass variables using a version number system for consistency provides more flexibility and structure without deviating from the basic principles."
related: ""
related_url: ""
photo: "light-lighter-lightest-lightestest.jpg"
thumbnail: ""
header: true
header_studio: false
header_title_gradient: false
---

Color variables in Sass or Less can be very tricky to maintain and extend. In this note, I explain the problems we encountered and how we solved them.

I believe that working with variables is important because it drastically improves the **consistency within a web application**—especially when working in a team with two or more designers. For example, one designer might use `lighten($gray-light, 10%)` while another uses `lighten($gray-light, 7%)`. This leads to subtle differences everywhere.

## Awkward Sass Variables

In Twitter Bootstrap, for example, you have [standard color variables](http://getbootstrap.com/css/#less-variables) like `$gray-light`, `$gray-lighter`, etc. But what do you do when you frequently need a shade of gray that falls exactly in between, without applying `lighten()` or `darken()`? Then you end up with names like `$gray-light-less` or `$gray-light-2`. It reminds me of my high school days when I made reports with filenames like “verslag-nieuw.doc”, “verslag-final.doc”, and “verslag-FINALFINAL.doc”. Horrible. Later on, this quickly changed to version numbers: v1, v2, v33, and so forth.

## CSS Variables with a System

Why not work with Sass variables using a kind of **version number system**? Then you get the following:

```
$color-neutral-10: #eee;
$color-neutral-30: #bbb;
$color-neutral-50: #fff;
$color-neutral-70: #444;
$color-neutral-90: #111;
```

Here we use `$color-neutral` instead of `$color-gray`, because the neutral tone within an application doesn’t always have to be literally gray—it can also be gray-blue.

This system is also more broadly applicable, for example with border-radius.

```
$border-radius-10: 0.25rem;
$border-radius-20: 0.75rem;
```

## Advantages

Such a system offers several advantages:

- Since we work in increments of ten, you can always add an extra variable—for example, `$color-neutral-35`. This way, you retain your creativity and freedom.
- For colors, `$color-neutral-0` is completely white and `$color-neutral-100` is completely black. This makes it very easy to distribute the various shades in between.
- For all variables within this system, you go from **nothing to something**. From white to black, and with border-radius and font-size from small to large.
- Avoid misusing a variable by wrapping it in `lighten()` or `darken()` within a module, as that would still lead to inconsistency.
- This system provides you with a standard set of variables, forcing you to work with these variables first rather than immediately adding a different color.

## Wrap-up

At Taiga, we work with the variable system described above. It has few limitations, but it does ensure a stronger consistency within a web application. It takes a bit of getting used to a system that supplies variables in a different way, but after one project, it becomes much easier. Please share your solution or idea in the comments!

Would you like to use it? The variable system described above is included in the [master version](https://github.com/aljanscholtens/taiga-boilerplate) of the Taiga boilerplate.

— This post was originally written on the Studio Wolf blog.
