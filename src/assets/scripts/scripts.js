(function($) {

  $(document).ready(function() {

    // Modal window
    // $('.js-modal__toggle').click(function() {
    //   $('.c-modal').toggleClass('is-open');
    //   $('body').toggleClass('u-overflow-hidden');
    // });
    //
    // // This makes all the content in a timeline item visible when in a small container
    // $('.c-timeline__clickable').click(function() {
    //   $(this).addClass('is-open');
    // });

    $("img.js-lazy").lazyload({
      threshold: 1000
    });

    // $('c-carousel').height($(window).height()/2);

    $('.c-carousel').flickity({
      // options
      cellAlign: 'center',
      initialIndex: 1,
      cellSelector: '.c-carousel__item',
      contain: true,
      freeScroll: true,
      wrapAround: true,
      pageDots: false,
      bgLazyLoad: 2,
      prevNextButtons: false
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
