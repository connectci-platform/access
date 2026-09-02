<?php

namespace Drupal\access\Plugin\openapi\OpenApiGenerator;

use Drupal\openapi\Plugin\openapi\OpenApiGeneratorBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Defines an OpenApi Schema Generator for ACCESS Announcements API v2.3.
 *
 * @OpenApiGenerator(
 *   id = "access_announcements_v23",
 *   label = @Translation("ACCESS Announcements API v2.3"),
 * )
 */
class AccessAnnouncementsV23Generator extends OpenApiGeneratorBase {

  /**
   * {@inheritdoc}
   */
  public function getApiName() {
    return 'ACCESS Announcements API v2.3';
  }

  /**
   * {@inheritdoc}
   */
  protected function getApiDescription() {
    return 'The ACCESS Announcements API v2.3 provides authenticated, acting-user endpoints for authoring announcements: create draft, update, delete, and list your own. Announcements are draft-only; per-group coordinator authorization applies.';
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
    $spec_file = DRUPAL_ROOT . '/modules/custom/access/openapi/announcements-api-2.3-openapi.yaml';

    if (file_exists($spec_file)) {
      $spec_content = file_get_contents($spec_file);
      $spec = Yaml::parse($spec_content);

      if (isset($spec['servers'])) {
        $current_server_url = $this->request->getSchemeAndHttpHost() . '/api/2.3';
        $production_url = 'https://support.access-ci.org/api/2.3';

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
        'name' => 'Announcements',
        'description' => 'ACCESS community announcements and news (acting-user authoring endpoints)',
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
