<?php

namespace Drupal\access\Plugin\openapi\OpenApiGenerator;

use Drupal\openapi\Plugin\openapi\OpenApiGeneratorBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Defines an OpenApi Schema Generator for ACCESS Announcements API.
 *
 * @OpenApiGenerator(
 *   id = "access_announcements",
 *   label = @Translation("ACCESS Announcements API v2.2"),
 * )
 */
class AccessAnnouncementsGenerator extends OpenApiGeneratorBase {

  /**
   * {@inheritdoc}
   */
  public function getApiName() {
    return 'ACCESS Announcements API v2.2';
  }

  /**
   * {@inheritdoc}
   */
  protected function getApiDescription() {
    return 'The ACCESS Announcements API provides access to news, updates, and announcements from the ACCESS community.';
  }

  /**
   * {@inheritdoc}
   */
  public function getBasePath() {
    return '/api/2.2';
  }

  /**
   * {@inheritdoc}
   */
  public function getSpecification() {
    // Load the static OpenAPI specification file.
    $spec_file = DRUPAL_ROOT . '/modules/custom/access/openapi/announcements-api-2.2-openapi.yaml';

    if (file_exists($spec_file)) {
      $spec_content = file_get_contents($spec_file);
      $spec = Yaml::parse($spec_content);

      // Add current server to the list for local development/testing.
      if (isset($spec['servers'])) {
        $current_server_url = $this->request->getSchemeAndHttpHost() . '/api/2.2';
        $production_url = 'https://support.access-ci.org/api/2.2';

        // Only add current server if it's different from production.
        if ($current_server_url !== $production_url) {
          array_unshift($spec['servers'], [
            'url' => $current_server_url,
            'description' => 'Current server',
          ]);
        }
      }

      return $spec;
    }

    // Fallback if file doesn't exist.
    return parent::getSpecification();
  }

  /**
   * {@inheritdoc}
   */
  public function getPaths() {
    // This is handled by getSpecification() which loads the complete spec.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getTags() {
    return [
      [
        'name' => 'Announcements',
        'description' => 'ACCESS community announcements and news',
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
    // Not used for static specifications.
    return [];
  }

}
