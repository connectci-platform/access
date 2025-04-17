(function (Drupal, drupalSettings) {

  'use strict';

  /**
   * Attaches the JS test behavior to weight div.
   */
  Drupal.behaviors.oodevents = {
    attach: function (context, settings) {
      var affinityGroup = drupalSettings.access_misc.ag;
      var peopleLink = document.querySelectorAll('[data-drupal-link-system-path="events"]');
      peopleLink.forEach(function (link) {
        if (!link.href.includes('?')) {
          link.href = link.href + '?facets_query=&f%5B0%5D=custom_event_affinity_group%3A' + affinityGroup;
        }
      });
    }
  };
})(Drupal, drupalSettings);
