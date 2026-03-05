(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.organizationToggle = {
    attach: function (context, settings) {
      once('organization-toggle', '[name="field_access_organization[0][target_id]"]', context).forEach(function (element) {
        var $orgField = $(element);
        var $institutionField = $('[name="field_institution[0][value]"]').closest('.field--name-field-institution');

        function toggleInstitutionField() {
          var selectedValue = $orgField.val();

          // Check if the value contains "Other" and node ID 3695
          // Autocomplete fields might have format like "Other (3695)"
          if (selectedValue === '3695' ||
              (selectedValue && selectedValue.includes('3695')) ||
              (selectedValue && selectedValue.toLowerCase().includes('other'))) {
            $institutionField.show();
            $institutionField.find('input').prop('required', TRUE);
          } else {
            $institutionField.hide();
            $institutionField.find('input').prop('required', FALSE);
          }
        }

        // Initial state
        toggleInstitutionField();

        // Listen for changes - multiple events to catch all scenarios
        $orgField.on('change keyup input', toggleInstitutionField);

        // Handle autocomplete selections
        $orgField.on('autocompleteselect autocompletechange', function (event, ui) {
          setTimeout(toggleInstitutionField, 100);
        });

        // Also check periodically in case value changes via other means
        setInterval(function () {
          var currentValue = $orgField.val();
          if (currentValue !== $orgField.data('lastValue')) {
            $orgField.data('lastValue', currentValue);
            toggleInstitutionField();
          }
        }, 500);
      });
    }
  };

})(jQuery, Drupal, once);