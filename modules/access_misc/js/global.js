// Heading for filters on mobile.
(function ($, Drupal, once) {
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

  // Restore focus to the last-clicked facet checkbox after page/AJAX reload.
  Drupal.behaviors.accessMiscFacetFocus = {
    attach: function (context) {
      // Store the clicked checkbox ID before the page reloads or AJAX refreshes.
      $(once('facet-focus-track', 'input.facets-checkbox', context)).on('change.facetFocus', function () {
        var $checkbox = $(this);
        var isReset = $checkbox.closest('.facets-reset').length > 0;

        if (isReset) {
          // "All" reset checkbox: store the parent facet ID so we can focus
          // the first real checkbox in that facet after it reloads.
          var facetId = $checkbox.closest('[data-drupal-facet-id]').attr('data-drupal-facet-id');
          sessionStorage.setItem('accessFacetFocusFacet', facetId);
          sessionStorage.removeItem('accessFacetFocusId');
        }
        else {
          sessionStorage.setItem('accessFacetFocusId', this.id);
          sessionStorage.removeItem('accessFacetFocusFacet');
        }
      });

      // Restore focus after reload.
      var focusId = sessionStorage.getItem('accessFacetFocusId');
      var focusFacet = sessionStorage.getItem('accessFacetFocusFacet');

      if (focusId) {
        var $target = $('#' + CSS.escape(focusId), context);
        if ($target.length) {
          sessionStorage.removeItem('accessFacetFocusId');
          setTimeout(function () {
            $target.trigger('focus');
          }, 100);
        }
      }
      else if (focusFacet) {
        // After an "All" reset, focus the first non-reset, non-honeypot checkbox.
        var $facet = $('[data-drupal-facet-id="' + focusFacet + '"]', context);
        if ($facet.length) {
          var $firstCheckbox = $facet.find('li:not(.facets-reset):not(.honey) > input.facets-checkbox').first();
          if ($firstCheckbox.length) {
            sessionStorage.removeItem('accessFacetFocusFacet');
            setTimeout(function () {
              $firstCheckbox.trigger('focus');
            }, 100);
          }
        }
      }
    }
  };

})(jQuery, Drupal, once);
