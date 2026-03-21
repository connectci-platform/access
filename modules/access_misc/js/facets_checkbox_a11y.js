/**
 * @file
 * Overrides facets checkbox widget for improved screen reader accessibility.
 *
 * Wraps the checkbox inside the label element instead of using separate
 * checkbox and label elements with for/id association. This ensures VoiceOver
 * on iPad reads the checkbox and label as a single element.
 */

(function ($, Drupal, once) {

  'use strict';

  /**
   * Turns all facet links into checkboxes.
   *
   * Overrides Drupal.facets.makeCheckboxes to fix the indeterminate selector
   * since the checkbox is now nested inside the label.
   */
  Drupal.facets.makeCheckboxes = function (context) {
    var $checkboxWidgets = $(once('facets-checkbox-transform', '.js-facets-checkbox-links', context));

    if ($checkboxWidgets.length > 0) {
      $checkboxWidgets.each(function (index, widget) {
        var $widget = $(widget);
        var $widgetLinks = $widget.find('.facet-item > a');

        $widget.addClass('js-facets-widget');
        $widgetLinks.each(Drupal.facets.makeCheckbox);
        Drupal.attachBehaviors(this.parentNode, Drupal.settings);
      });
    }

    // Use descendant selector instead of direct child selector since the
    // checkbox is now wrapped inside a label element.
    $('.facet-item--expanded.facet-item--active-trail input.facets-checkbox').prop('indeterminate', true);
  };

  /**
   * Replace a link with a checked checkbox wrapped inside a label.
   *
   * Overrides Drupal.facets.makeCheckbox to wrap the checkbox inside the label
   * element for implicit association, which is more robust for screen readers
   * than the for/id attribute pattern.
   */
  Drupal.facets.makeCheckbox = function () {
    var $link = $(this);
    var active = $link.hasClass('is-active');
    var description = $link.html();
    var href = $link.attr('href');
    var id = $link.data('drupal-facet-item-id');

    var checkbox = $('<input type="checkbox" class="facets-checkbox">')
      .attr('id', id)
      .data($link.data())
      .data('facetsredir', href);

    // Wrap checkbox inside label for implicit association.
    var label = $('<label class="facets-checkbox-label"></label>')
      .append(checkbox)
      .append(' ')
      .append(description);

    checkbox.on('change.facets', function (e) {
      e.preventDefault();

      var $widget = $(this).closest('.js-facets-widget');

      Drupal.facets.disableFacet($widget);
      $widget.trigger('facets_filter', [href]);
    });

    if (active) {
      checkbox.attr('checked', true);
      label.find('.js-facet-deactivate').remove();
    }

    $link.before(label).hide();
  };

})(jQuery, Drupal, once);
