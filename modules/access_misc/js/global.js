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
