---
title: "How to set up an inline SVG sprite"
date: 2015-01-08
slug: "how-to-set-up-an-inline-svg-sprite"
draft: false
author: "aljan"

subtitle: ""
description: ""

related: ""
related_url: ""

photo: "how-to-set-up-an-inline-svg-sprite.jpg"
thumbnail: ""

header: true
header_studio: false
header_title_gradient: false
---

Years ago I worked with large PNG sprites with tons of icons, logo’s and other layout supporting images. Simply because it was the most efficient way to load images with the smallest amount of connections. Today SVG is supported by all major browsers. In this article I'll explain how to set up an inline SVG sprite.

Back in the days designing for the web was hard. We had to use sprites for almost everything. Fortunately the web grew better. CSS transformations, border radiuses, gradients and much more fancy techniques entered our workspace. Now, only icons were left in the PNG sprite.

## Font icons

Sadly, working with PNG causes a mayor issue. PNG is not a vector format. The solution: put icons in fonts. Font icons! Font icons made me happy. They’re vector and they kept me out of Photoshop to create sprites. But there’s also a downside: performance and flexibility. Mostly I only use around 10 icons and still needed to load an icon font with 200 icons over 100kb in size. It felt a little stupid.

Tip: With [IcoMoon](https://icomoon.io/) you can build your own icon fonts with less icons.

## Scalable Vector Graphics

Alright, let’s move on to the good stuff. Since SVG is widely [supported across browsers](http://caniuse.com/#feat=svg) it sounds like a good solution to replace icon fonts with SVG. SVG stands for Scalable Vector Graphics and are scalable, adjustable and even animatable.

So how do you make your own inline SVG sprite? Let’s get started!

## Setting up a SVG element

We’ll place our SVG sprite inline in the HTML by wrapping it in a SVG element.

The best place to include a SVG element is after the `<body>` opening tag. A SVG element exists out of one or multiple symbols. Each symbol is an image. A symbol contains a path element. We’ll get back to this later. Make sure you place the SVG right after the `<body>`, if you don’t do this, Safari won’t show the images.

```
<body>

  <svg xmlns="http://www.w3.org/2000/svg">
    <symbol>
      <path />
    </symbol>
  </svg>

  <!-- Your HTML -->

</body>
```

Don’t forget to hide your SVG! If you don’t hide it, your images will be shown on the top of your page. Also, don’t use `display: none;`, this will hide all your symbols used on the page. The best way is to use `visibility: hidden;` in combination with `position: absolute;` and `z-index: -1;`.

Besides an inline SVG you can also use a real .svg file, which can be cached! More on this later.

## Create your SVG sprite

The first step is to open up Illustrator or Sketch and create/open a shape or icon. To create a SVG icon you need two things:

- **Path**: paths are used to draw advanced shapes combined from lines, arcs, curves etc. with or without fill. In fact, it is your image. Check out this [video tutorial](https://www.youtube.com/watch?v=k6TWzfLGAKo&amp;list=PLL8woMHwr36F2tCFnWTbVBQAGQ6nTcXOO) on paths.
- **Viewbox**: if you think of the whole SVG image as a canvas, the view box is part of the canvas you want the viewer to see. A more elaborate description of viewports is available [here](http://tutorials.jenkov.com/svg/svg-viewport-view-box.html).

For Illustrator choose ‘Save as’ and pick ‘SVG’. After clicking ‘Save’ you’ll get the option ‘SVG Code’, open it and copy the path(s). If you use Sketch you can create a new art board and export it as SVG. Open the SVG and copy the path(s).

```
<path d="M8.914,5.79C9.039,5.379,9.188,4.951,9.188,4.5c0-2.485-1.983-4.5-4.469-4.5S0.234,2.015,0.234,4.5S2.257,9,4.742,9C5.193,9,5.5,8.913,5.91,8.79L6,9v2h2v2h2v2h2.25l1,1H16v-3L8.914,5.79z M3.75,5c-0.829,0-1.5-0.671-1.5-1.5S2.921,2,3.75,2s1.5,0.671,1.5,1.5S4.579,5,3.75,5z" />
```

If all your icons have the same size, for example 16×16 units, then you should create an Illustrator file or Sketch art board with that specific size/aspect ratio. Since we’re working with vector graphics here, units only define the aspect ratio. 16×16 is the same as 4×4. Use the units in your viewbox as follows:

```
<symbol viewBox="0 0 16 16" />
```

## Putting your inline SVG together

If we copy our viewbox and path into our inline SVG element, we’ll get the following:

```
<svg xmlns="http://www.w3.org/2000/svg">
  <symbol viewBox="0 0 16 16" id="icon-id" preserveAspectRatio="xMinYMin">
    <path d="M8.914,5.79C9.039,5.379,9.188,4.951,9.188,4.5c0-2.485-1.983-45-4.469-4.5S0.234,2.015,0.234,4.5S2.257,9,4.742,9C5.193,9,5.5,8.913,5.91,8.79L6,9v2h2v2h2v2h2.25l1,1H16v-3L8.914,5.79zM3.75,5c-0829,0-1.5-0.671-1.5-1.5S2.921,2,3.75,2s1.5,0.671,1.5,1.5S4.579,5,3.75,5z" />
  </symbol>
</svg>
```

In this example, SVG only contains one symbol. Add more to create a real sprite. Note that I added two more things to the symbol element: the `preserveAspectRatio` to keep the original aspect ratio and an ID to identify symbols so we can use them later on.

## Show your images to the world

So we have our SVG element, with one or more symbols inside it. We can now use the ID of a symbol, in this example `icon-id`, and include it in the web page with the `<use>` element:

```
<svg class="icon"><use xlink:href="#icon-id" /></svg>
```

You can do the same if you have an external SVG file, just type it like this:

```
<svg class="icon"><use xlink:href="path/to/file.svg#icon-id" /></svg>
```

The last step is adding some CSS to the class `icon` as defined above. Style your icon the way you want it. The `fill` property is the color of your symbol.

```
svg.icon {
  height: 16px;
  width: 16px;
  display: inline-block;
  fill: green;
}
```

## Wrap up

SVG sprites are a new way to efficiently add scalable icons to your designs. Making a SVG sprite is not hard. Follow these steps and you’re right on your way:

- Set up the inline SVG file wrapper;
- Create a SVG symbol via Illustrator or Sketch and get the paths and viewbox and place it inside your SVG element;
- Include a symbol from your SVG sprite on your web page with `<use>`;
- Style the symbol with CSS.

Do you work with [Taiga Boilerplate](https://github.com/AljanScholtens/taiga-boilerplate)? This approach is also embedded in Taiga.

— This post was originally written on the Studio Wolf blog.