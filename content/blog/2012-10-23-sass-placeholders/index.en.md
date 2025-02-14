---
title: "Sass Placeholders"
date: 2012-10-23
slug: "sass-placeholders"
draft: false
author: "aljan"
subtitle: ""
description: "Sass Placeholders help reduce repetition in CSS by using the %selector. Through @extend, we can apply common styles without duplicating code in the output."
related: ""
related_url: ""
photo: "sass-placeholders.jpg"
thumbnail: ""
header: false
header_studio: false
header_title_gradient: false
---

{{<image src="sass-placeholders.jpg">}}

A common problem with HTML is the number of classes we use, for example `<div class="product product-hat">`. Duplicating CSS also happens too often in order to achieve the desired result. There is a solution.

During the [Smashing Conference](http://smashingconf.com/) in Freiburg, Nicole Sullivan ([@Stubornella](https://twitter.com/stubbornella)) talked about the [SASS placeholder](http://sass-lang.com/docs/yardoc/file.SASS_REFERENCE.html#placeholder_selectors_) (available since SASS 3.2). The placeholder is a type of selector that does not appear in the compiled CSS output.

Where we would normally use `.selector` or `#selector`, we now use `%selector`. The `%product` below does nothing on its own; it does not appear in the CSS output.

```
%product {
  background: grey;
  border: 1px solid;
  padding: 1em;
}
```

But we can indeed do something with it. The advantage lies in the combination with [@extend](http://sass-lang.com/docs/yardoc/file.SASS_REFERENCE.html#extend).

In CSS, we typically use something like `div.product`. Suppose we sell shoes and hats through an online store. In the product overview, all products share certain properties such as background, border, and padding. Only the shoes and hats differ in border color, as shown in the following SCSS:

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

*Tip: The `@extend` is always loaded last within the selector, so overriding can be tricky.*

In this way, `%product` does not appear in the output, but both the shoe and the hat can still use the properties of `%product`. This produces the following output:

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

This makes the HTML simpler and more **semantic**; you no longer get `<div class="product product-hat">`, but only `<div class="product-hat">`.

Additionally, in the SCSS you don’t have to duplicate code for both `.product-shoe` and `.product-hat`. **Less code.**

As an interface designer, this results in a more maintainable framework and better performance for the user.

— This post was originally written on the Studio Wolf blog.