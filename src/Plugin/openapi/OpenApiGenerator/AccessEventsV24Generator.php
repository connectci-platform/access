<?php

namespace Drupal\access\Plugin\openapi\OpenApiGenerator;

use Drupal\openapi\Plugin\openapi\OpenApiGeneratorBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Defines an OpenApi Schema Generator for ACCESS Events API v2.4.
 *
 * @OpenApiGenerator(
 *   id = "access_events_v24",
 *   label = @Translation("ACCESS Events API v2.4"),
 * )
 */
class AccessEventsV24Generator extends OpenApiGeneratorBase {

  /**
   * {@inheritdoc}
   */
  public function getApiName() {
    return 'ACCESS Events API v2.4';
  }

  /**
   * {@inheritdoc}
   */
  protected function getApiDescription() {
    return 'The ACCESS Events API provides access to upcoming and past events, trainings, webinars, and office hours. v2.4 emits start_date and end_date as true UTC instants; earlier versions stamped a Z on a site-local clock. Events also carry their own timezone.';
  }

  /**
   * {@inheritdoc}
   */
  public function getBasePath() {
    return '/api/2.4';
  }

  /**
   * {@inheritdoc}
   */
  public function getSpecification() {
    $spec_file = DRUPAL_ROOT . '/modules/custom/access/openapi/events-api-2.4-openapi.yaml';

    if (file_exists($spec_file)) {
      $spec_content = file_get_contents($spec_file);
      $spec = Yaml::parse($spec_content);

      if (isset($spec['servers'])) {
        $current_server_url = $this->request->getSchemeAndHttpHost() . '/api/2.4';
        $production_url = 'https://support.access-ci.org/api/2.4';

        if ($current_server_url !== $production_url) {
          array_unshift($spec['servers'], [
            'url' => $current_server_url,
            'description' => 'Current server'
          ]);
        }
      }

      return $spec;
    }

    return parent::getSpecification();
  }

  /**
   * {@inheritdoc}
   */
  public function getPaths() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getTags() {
    return [
      [
        'name' => 'Events',
        'description' => 'ACCESS events, trainings, and webinars',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getProduces() {
    return ['application/json'];
  }

  /**
   * {@inheritdoc}
   */
  protected function getJsonSchema($described_format, $entity_type_id, $bundle_name = NULL) {
    return [];
  }

}
