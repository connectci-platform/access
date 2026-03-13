// Checbox list behavior for accessibility and keyboard navigation,
// and a heading for filters on mobile.
(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.accessMiscCheckboxList = {
    attach: function (context, settings) {
      once('access-misc-checkbox-list', 'ul.item-list__checkbox', context).forEach(function (list) {
        list.querySelectorAll('li').forEach(function (item, index) {
          item.setAttribute('aria-checked', 'false');
          item.setAttribute('tabindex', '-1');
          item.setAttribute('aria-selected', index === 0 ? 'true' : 'false');
        });

        list.querySelectorAll('[type=checkbox]').forEach(function (checkbox) {
          checkbox.addEventListener('click', function (e) {
            if (this.checked) {
              this.parentElement.parentElement.setAttribute('aria-checked', 'true');
            } else {
              this.parentElement.parentElement.setAttribute('aria-checked', 'false');
            }
            e.stopPropagation();
          });
        });

        list.addEventListener('keydown', function (e) {
          var currentItem = this.querySelector('[aria-selected=true]');
          if (!currentItem) return;
          switch (e.keyCode) {
            case 38: // Up arrow
              if (currentItem.previousElementSibling !== null) {
                currentItem.setAttribute('aria-selected', 'false');
                currentItem.classList.remove('active');
                currentItem.previousElementSibling.setAttribute('aria-selected', 'true');
                currentItem.previousElementSibling.classList.add('active');
                currentItem.previousElementSibling.focus();
              }
              e.preventDefault();
              break;
            case 40: // Down arrow
              if (currentItem.nextElementSibling !== null) {
                currentItem.setAttribute('aria-selected', 'false');
                currentItem.classList.remove('active');
                currentItem.nextElementSibling.setAttribute('aria-selected', 'true');
                currentItem.nextElementSibling.classList.add('active');
                currentItem.nextElementSibling.focus();
              }
              e.preventDefault();
              break;
            case 32: // Space
              var checkbox = currentItem.querySelector('input[type=checkbox]');
              if (checkbox) {
                if (currentItem.getAttribute('aria-checked') === 'true') {
                  currentItem.setAttribute('aria-checked', 'false');
                  checkbox.checked = false;
                } else {
                  currentItem.setAttribute('aria-checked', 'true');
                  checkbox.checked = true;
                }
                $(checkbox).trigger('change');
              }
              e.preventDefault();
              break;
          }
        });
      });
    }
  };

  Drupal.behaviors.accessMiscFiltersHeading = {
    attach: function (context, settings) {
      // Add "Filters" heading before the first facet block
      var $firstBlock = $('.block-facet--checkbox', context).first();
      if ($firstBlock.length && !$firstBlock.prev('h2.md--hidden').length) {
        $firstBlock.before('<h2 class="md--hidden d-block d-lg-none">Filters</h2>');
      }
    }
  };

})(jQuery, Drupal, once);
