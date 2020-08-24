(function($) {

  $(document).ready(function() {

    $("img.js-lazy").lazyload({
      threshold: 1000
    });

    // $('.c-timeline__clickable').click(function() {
    //   $(this).parent().find('.c-accordion__content').slideToggle(100);
    //   $(this).parent().toggleClass('is-open');
    // });

    //zoekt hij eerste parent op
    // $('.js-accordion__toggle').click(function() {
    //   $(this).parent('.js-accordion').toggleClass('is-open');
    // });

    // Dit kan ook
    // $('.js-accordion__toggle').click(function() {
    //   $(this).next().find('.js-accordion__content').toggleClass('is-open');
    // });

  });

})(jQuery);
