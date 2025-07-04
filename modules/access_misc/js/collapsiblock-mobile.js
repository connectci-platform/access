(function ($) {
  $(document).ready(function () {
    // If window width is below 768px, make blocks collapsed
    if ($(window).width() < 768) {
      $('.collapsiblockTitle:not(.collapsiblockTitleCollapsed)').addClass('collapsiblockTitleCollapsed');
      $('[aria-expanded="true]').attr('aria-expanded', 'false');
      $('.collapsiblockContent:not(.collapsiblockContentCollapsed)').addClass('collapsiblockContentCollapsed');
      $('.collapsiblockContent').attr('style', 'display: none;');
    }
  });
})(jQuery);
