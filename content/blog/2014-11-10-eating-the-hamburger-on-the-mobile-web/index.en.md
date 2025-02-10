---
title: "Eating the hamburger on the mobile web"
date: 2014-11-10
slug: "eating-the-hamburger-on-the-mobile-web"
draft: false
author: "aljan"

subtitle: ""
description: "Exploring alternatives to the hamburger icon for mobile navigation, highlighting issues with iOS Safari and proposing bottom navigation for easier access and visibility of website elements on mobile."

related: ""
related_url: ""

photo: "eating-the-hamburger-on-the-mobile-web.jpg"
thumbnail: ""

header: true
header_studio: false
header_title_gradient: false
---

People have been using the hamburger-icon on websites since [at least the 1990’s](http://blog.placeit.net/history-of-the-hamburger-icon/). Since responsive websites and mobile apps have grown in popularity, usage of the hamburger-icon has exploded. We’re searching for a better mobile navigation for the web and would like to share our ideas and the issues we’re encountering. Today’s issue is iOS Safari.

## Navigating in Apps

Lately there’s been a lot of [discussion about the icon](http://mor10.com/hamburger-bad/). A short while ago [Apple also wrote a piece](http://blog.manbolo.com/2014/06/30/apple-on-hamburger-menus) on the usage of the hamburger as a way of navigating.

According to Apple the icon is abstract and does not trigger users enough. Luke Wroblewski also wrote a blog about the hamburger on [large mobile screens](http://www.lukew.com/ff/entry.asp?1927).

The icon isn’t very self-explanatory and is hard to reach with a single hand on large screens.

## What about the mobile web?

A lot of these articles and opinions are on the usage of this icon in apps, but it’s important not to forget websites.

In fact, on the web you basically run into the same problems:

- Your screen might be too large to easily reach the navigation item sitting at the top of your screen.
- The purpose of the hamburger icon isn’t very obvious and thus doesn’t invite users to use it.
- The use of such an icon also hides (most of) the navigation completely by default so that users have to tap or click before seeing the navigation.

An easy way to solve this problem is to place a navigation bar at the bottom of the screen. This immediately shows which other elements are on the website and makes the menu easily reachable on both small and large screens.

## Kielzog as an example

One example of this usage is the website of Kielzog, a project we’re working on right now. Kielzog is a local theater and centre of arts. A lot of visitors only know Kielzog for its theater performances and are unaware that the theater also offers lessons in music, dance, theater and fine arts. With the full navigation directly visible, a user can easily scan through the different pages (services) the theater has to offer. These items are easily overlooked when the user has to tap a menu-icon first.

On desktop the navigation at the top of the website is fully visible but on mobile this is harder to achieve. This is why we made the choice to show the navigation at the bottom of the screen when visiting the website on mobile.

This solution allows the user to easily see other elements on the website and makes it easy for users to navigate without having to reposition their hand. By showing the navigation in full on mobile, the user immediately sees what other services the theater has to offer.

{{<image src="eating-the-hamburger-on-the-mobile-web-1.jpg">}}

This sounds like a proper alternative for the hamburger-icon, an alternative that Apple hopefully approves of. Sounds all good, except for one problem. The native usage of mobile Safari causes the browser to pop-up its buttons when a user taps on an item in the navigation. This results in users having to tap a menu-item twice before it leads them to the next page. Having to tap twice is unacceptable, of course. The same goes for non-sticky links at the bottom of a page—these are equally untappable the first time.

To conclude all of this, it basically comes down to us, the developers, not being able to implement an easy way for the user to navigate by using a navigation at the bottom of the screen (at least, without having to tap twice).

What consensus are we looking for here? Should we make the hamburger more descriptive by adding the word “menu” next to it? Or should we look for another implementation of the mobile navigation altogether? I’m curious to find out how Apple and you guys think about this problem.

— This post was originally written on the Studio Wolf blog.
