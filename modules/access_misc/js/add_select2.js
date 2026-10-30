(function ($, Drupal) {
  $(document).ready(function () {
    // Alphabetize the options in the affected resources list.
    // jQuery 4 removed .sort() from jQuery objects, so sort a plain array.
    $("#edit-field-affected-infrastructure-target-id").html($("#edit-field-affected-infrastructure-target-id option").get().sort(function (a, b) {
      if (b.text != 'All') {
        return a.text == b.text ? 0 : a.text < b.text ? -1 : 1
      }
    }))
    $("#edit-field-affected-infrastructure-target-id").select2({});
  });
})(jQuery, Drupal);
