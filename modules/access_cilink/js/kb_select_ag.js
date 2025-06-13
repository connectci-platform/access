console.log("Knowledge Base Select Agent");

(function ($, Drupal) {
  $(document).ready(function () {

    $("#edit-field-ag-nodes-select").select2({});

    // Get item selected in #edit-field-ag-nodes-select.
    $("#edit-field-ag-nodes-select").on("select2:select", function (e) {
      var data = e.params.data;
      console.log("Selected item:", data);
      // Select the same item in #edit-affinity-group.
      $("#edit-affinity-group").val(data.id).trigger("change");
    });

  // if #edit-approved checkbox is checked, set #edit-field-ag-nodes-select to disabled
  $("#edit-approved").on("change", function () {
    if ($(this).is(":checked")) {
      $("#edit-field-ag-nodes-select").prop("disabled", true);
      $('.js-form-item-field-ag-nodes-select').addClass('form-disabled');
    } else {
      $("#edit-field-ag-nodes-select").prop("disabled", false);
      $('.js-form-item-field-ag-nodes-select').removeClass('form-disabled');
    }
  });

  });
})(jQuery, Drupal);
