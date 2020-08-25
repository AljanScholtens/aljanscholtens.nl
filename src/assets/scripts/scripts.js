(function($) {

  $(document).ready(function() {


    // Lazy load images
    $("img.js-lazy").lazyload({
      threshold: 1000
    });


    // Gallery fancybox
    $('[data-fancybox="gallery"]').fancybox({
    	animationDuration: 200
    });


    // Dark mode
    var mq = window.matchMedia( '(prefers-color-scheme: dark)' );
    if ( mq.matches ){
      $( "html" ).addClass( "t-dark" );
    }

    // const prefersDarkScheme = window.matchMedia("(prefers-color-scheme: dark)");

    // if (prefersDarkScheme.matches) {
    //   document.html.classList.add("dark-theme");
    // } else {
    //   document.html.classList.remove("dark-theme");
    // }

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
