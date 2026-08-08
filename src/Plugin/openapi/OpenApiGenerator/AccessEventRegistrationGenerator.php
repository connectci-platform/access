<?php

namespace Drupal\access\Plugin\openapi\OpenApiGenerator;

use Drupal\openapi\Plugin\openapi\OpenApiGeneratorBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Defines an OpenApi Schema Generator for the ACCESS Event Registration API v1.0.
 *
 * @OpenApiGenerator(
 *   id = "access_event_registration",
 *   label = @Translation("ACCESS Event Registration API v1.0"),
 * )
 */
class AccessEventRegistrationGenerator extends OpenApiGeneratorBase {

  /**
   * {@inheritdoc}
   */
  public function getApiName() {
    return 'ACCESS Event Registration API v1.0';
  }

  /**
   * {@inheritdoc}
   */
  protected function getApiDescription() {
    return 'The ACCESS Event Registration API v1.0 provides authenticated, acting-user endpoints for event registration: self-registration (preview then commit), and listing and cancelling your own registrations.';
  }

  /**
   * {@inheritdoc}
   */
  public function getBasePath() {
    return '/api/1.0';
  }

  /**
   * {@inheritdoc}
   */
  public function getSpecification() {
    $spec_file = DRUPAL_ROOT . '/modules/custom/access/openapi/event-registration-api-1.0-openapi.yaml';

    if (file_exists($spec_file)) {
      $spec_content = file_get_contents($spec_file);
      $spec = Yaml::parse($spec_content);

      if (isset($spec['servers'])) {
        $current_server_url = $this->request->getSchemeAndHttpHost() . '/api/1.0';
        $production_url = 'https://support.access-ci.org/api/1.0';

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
        'name' => 'Registrations',
        'description' => 'Acting-user self-registration and the acting user\'s own registrations (list and cancel)',
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
