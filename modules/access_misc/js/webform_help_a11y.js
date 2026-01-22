/**
 * @file
 * Accessibility fix for webform element help tooltips.
 *
 * By default, Tippy.js disables aria-describedby for interactive tooltips.
 * This ensures screen readers announce the help content when the tooltip opens.
 */

(function (Drupal) {
  'use strict';

  Drupal.webform = Drupal.webform || {};
  Drupal.webform.elementHelpIcon = Drupal.webform.elementHelpIcon || {};
  Drupal.webform.elementHelpIcon.options = {
    aria: {
      content: 'describedby'
    }
  };

})(Drupal);
