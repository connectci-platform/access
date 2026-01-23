(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.accessMiscFiltersHeading = {
    attach: function (context, settings) {
      // Add "Filters" heading before the first facet block
      var $firstBlock = $('.block-facet--checkbox', context).first();
      if ($firstBlock.length && !$firstBlock.prev('h2.md--hidden').length) {
        $firstBlock.before('<h2 class="md--hidden d-block d-lg-none">Filters</h2>');
      }
    }
  };

  Drupal.behaviors.accessMiscAnchorFocus = {
    attach: function (context, settings) {
      // Select all internal anchor links starting with # within the current context,
      // ensuring we only bind the click handler once per element.
      once('access-misc-anchor-focus', 'a[href^="#"]', context).forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
          var href = anchor.getAttribute('href');
          if (!href) {
            return;
          }
          var targetId = href.substring(1);
          if (!targetId) {
            return;
          }
          var target = document.getElementById(targetId);
          if (target) {
            // Temporarily make the element focusable
            target.setAttribute('tabindex', '-1');

            // Move focus to the target without scrolling the page
            target.focus({ preventScroll: true });

            // Optional: remove tabindex after a short delay
            setTimeout(function () {
              target.removeAttribute('tabindex');
            }, 1000); // 1 second delay is enough for screen reader to announce
          }
        });
      });
    }
  };

})(jQuery, Drupal);
