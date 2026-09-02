// Heading for filters on mobile.
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.accessMiscFiltersHeading = {
    attach: function (context, settings) {
      // Add "Filters" heading before the first facet block
      var firstBlock = context.querySelector('.block-facet--checkbox');
      if (firstBlock) {
        var prev = firstBlock.previousElementSibling;
        if (!prev || !prev.matches('h2.md--hidden')) {
          firstBlock.insertAdjacentHTML('beforebegin', '<h2 class="md--hidden d-block d-lg-none">Filters</h2>');
        }
      }
    }
  };

  // Restore focus to the last-clicked facet checkbox after page/AJAX reload.
  Drupal.behaviors.accessMiscFacetFocus = {
    attach: function (context) {
      // Store the clicked checkbox ID before the page reloads or AJAX refreshes.
      once('facet-focus-track', 'input.facets-checkbox', context).forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
          var isReset = this.closest('.facets-reset') !== null;

          if (isReset) {
            // "All" reset checkbox: store the parent facet ID so we can focus
            // the first real checkbox in that facet after it reloads.
            var facet = this.closest('[data-drupal-facet-id]');
            var facetId = facet ? facet.getAttribute('data-drupal-facet-id') : '';
            sessionStorage.setItem('accessFacetFocusFacet', facetId);
            sessionStorage.removeItem('accessFacetFocusId');
          }
          else {
            sessionStorage.setItem('accessFacetFocusId', this.id);
            sessionStorage.removeItem('accessFacetFocusFacet');
          }
        });
      });

      // Restore focus after reload.
      var focusId = sessionStorage.getItem('accessFacetFocusId');
      var focusFacet = sessionStorage.getItem('accessFacetFocusFacet');

      if (focusId) {
        var target = context.querySelector('#' + CSS.escape(focusId));
        if (target) {
          sessionStorage.removeItem('accessFacetFocusId');
          setTimeout(function () {
            target.focus();
          }, 100);
        }
      }
      else if (focusFacet) {
        // After an "All" reset, focus the first non-reset, non-honeypot checkbox.
        var facet = context.querySelector('[data-drupal-facet-id="' + focusFacet + '"]');
        if (facet) {
          var firstCheckbox = facet.querySelector('li:not(.facets-reset):not(.honey) > input.facets-checkbox');
          if (firstCheckbox) {
            sessionStorage.removeItem('accessFacetFocusFacet');
            setTimeout(function () {
              firstCheckbox.focus();
            }, 100);
          }
        }
      }
    }
  };

  // Restore focus to anchor link after browser back (Alt+Left).
  // Hash navigation creates a history entry but the browser does not restore
  // focus on back, stranding keyboard / screen-reader users.
  Drupal.behaviors.accessMiscJumpLinkFocus = {
    attach: function (context) {
      once('jump-link-focus', 'a[href^="#"]', context).forEach(function (link) {
        link.addEventListener('click', function () {
          window._accessJumpLinkSource = this;
        });
      });

      if (!window._accessJumpLinkHashChange) {
        window._accessJumpLinkHashChange = true;
        window.addEventListener('hashchange', function () {
          var sourceLink = window._accessJumpLinkSource;
          if (!sourceLink) {
            return;
          }
          // Only restore focus when navigating away from the stored hash
          // (i.e. browser back), not when first clicking the link.
          if (location.hash === sourceLink.getAttribute('href')) {
            return;
          }
          window._accessJumpLinkSource = null;
          // Delay slightly to let the browser finish scroll restoration.
          setTimeout(function () {
            sourceLink.focus();
          }, 50);
        });
      }
    }
  };

  // #a11y fixes.
  Drupal.behaviors.accessMiscA11yFixes = {
    attach: function (context) {
      var toolbar = context.querySelector('#toolbar-administration');
      if (toolbar) {
        toolbar.setAttribute('role', 'navigation');
      }
      context.querySelectorAll('.messages').forEach(function (el) {
        el.setAttribute('role', 'status');
      });
      context.querySelectorAll('.access-support .form-type-vertical-tabs label.visually-hidden').forEach(function (el) {
        el.remove();
      });
    }
  };

})(Drupal, once);
