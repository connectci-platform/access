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

})(jQuery, Drupal);

document.addEventListener('DOMContentLoaded', () => {
  // Select all internal anchor links starting with #
  const anchorLinks = document.querySelectorAll('a[href^="#"]');

  anchorLinks.forEach(anchor => {
    anchor.addEventListener('click', e => {
      const targetId = anchor.getAttribute('href').substring(1);
      const target = document.getElementById(targetId);
      if (target) {
        // Temporarily make the element focusable
        target.setAttribute('tabindex', '-1');

        // Move focus to the target without scrolling the page
        target.focus({ preventScroll: true });

        // Optional: remove tabindex after a short delay
        setTimeout(() => {
          target.removeAttribute('tabindex');
        }, 1000); // 1 second delay is enough for screen reader to announce
      }
});

  });
});
