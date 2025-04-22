/**
 * @file
 * JavaScript behaviors for the Affinity Group node form.
 */

(function ($, Drupal) {

  'use strict';

  /**
   * Behavior for handling the visibility of private users field.
   */
  Drupal.behaviors.agNodeForm = {
    attach: function (context, settings) {
      // Use 'document' context to ensure we can find the elements
      var $privateCheckbox = $('#edit-field-ag-private-value');
      var $privateUsersWrapper = $('#edit-field-ag-private-users-wrapper');

      // Make sure elements exist before proceeding
      if ($privateCheckbox.length && $privateUsersWrapper.length) {
        // Set initial state on page load
        if ($privateCheckbox.is(':checked')) {
          $privateUsersWrapper.show();
        } else {
          $privateUsersWrapper.hide();
        }

        // Remove any existing handlers to prevent duplicates
        $privateCheckbox.off('change.agPrivate');
        
        // Add the change event handler with namespace
        $privateCheckbox.on('change.agPrivate', function() {
          if ($(this).is(':checked')) {
            $privateUsersWrapper.show();
          } else {
            $privateUsersWrapper.hide();
          }
        });
      }
    }
  };

})(jQuery, Drupal);

