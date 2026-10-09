<?php

namespace Drupal\access\Plugin\openapi\OpenApiGenerator;

use Drupal\openapi\Plugin\openapi\OpenApiGeneratorBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Defines an OpenApi Schema Generator for the ACCESS Events Write API v2.3.
 *
 * @OpenApiGenerator(
 *   id = "access_events_write",
 *   label = @Translation("ACCESS Events Write API"),
 * )
 */
class AccessEventsWriteGenerator extends OpenApiGeneratorBase {

  /**
   * {@inheritdoc}
   */
  public function getApiName() {
    return 'ACCESS Events Write API v2.3';
  }

  /**
   * {@inheritdoc}
   */
  protected function getApiDescription() {
    return 'The ACCESS Events Write API v2.3 provides authenticated, acting-user endpoints to create, update, archive, restore, and schedule events.';
  }

  /**
   * {@inheritdoc}
   */
  public function getBasePath() {
    return '/api/2.3';
  }

  /**
   * {@inheritdoc}
   */
  public function getSpecification() {
    $spec_file = DRUPAL_ROOT . '/modules/custom/access/openapi/events-write-api-2.3-openapi.yaml';

    if (file_exists($spec_file)) {
      $spec_content = file_get_contents($spec_file);
      $spec = Yaml::parse($spec_content);

      if (isset($spec['servers'])) {
        $current_server_url = $this->request->getSchemeAndHttpHost() . '/api/2.3';
        $production_url = 'https://support.access-ci.org/api/2.3';

        if ($current_server_url !== $production_url) {
          array_unshift($spec['servers'], [
            'url' => $current_server_url,
            'description' => 'Current server',
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
        'name' => 'Event series',
        'description' => 'Create, update, archive, restore, and send event series for review',
      ],
      [
        'name' => 'Event occurrences',
        'description' => 'Add, edit, cancel, and restore individual occurrences',
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
