(function ($) {
  $(document).ready(function () {
    ((Drupal, cookies, once) => {
      Drupal.CollapsiblockMisc = Drupal.CollapsiblockMisc || {};
      const cookieString = cookies.get('collapsiblock');
      const cookieData = cookieString ? JSON.parse(cookieString) : {};
      // If window width is below 768px, make blocks collapsed
      if ($(window).width() < 768) {
        Object.keys(cookieData).forEach((key) => {
          cookieData[key] = 0;
        });
        cookies.set(
          'collapsiblock',
          JSON.stringify(cookieData)
        );
        $('.collapsiblockTitle:not(.collapsiblockTitleCollapsed)').addClass('collapsiblockTitleCollapsed');
        $('[aria-expanded="true]').attr('aria-expanded', 'false');
        $('.collapsiblockContent:not(.collapsiblockContentCollapsed)').addClass('collapsiblockContentCollapsed');
        $('.collapsiblockContent').attr('style', 'display: none;');
      }
    })(Drupal, window.Cookies, once);
  });
})(jQuery);
