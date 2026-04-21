<?php

namespace Drupal\access\Plugin\openapi\OpenApiGenerator;

use Drupal\openapi\Plugin\openapi\OpenApiGeneratorBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Defines an OpenApi Schema Generator for the ACCESS Resource Documentation API.
 *
 * @OpenApiGenerator(
 *   id = "access_resources",
 *   label = @Translation("ACCESS Resource Documentation API"),
 * )
 */
class AccessResourcesGenerator extends OpenApiGeneratorBase {

  /**
   * {@inheritdoc}
   */
  public function getApiName() {
    return 'ACCESS Resource Documentation API';
  }

  /**
   * {@inheritdoc}
   */
  protected function getApiDescription() {
    return 'Public JSON endpoints for ACCESS resource provider documentation. Drives syndication to Elastic search and the RAG ingestion pipeline.';
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
    $spec_file = DRUPAL_ROOT . '/modules/custom/access/openapi/resources-api-1.0-openapi.yaml';

    if (file_exists($spec_file)) {
      $spec = Yaml::parse(file_get_contents($spec_file));

      if (isset($spec['servers'])) {
        $current_server_url = $this->request->getSchemeAndHttpHost() . '/api/1.0';
        $production_url = 'https://support.access-ci.org/api/1.0';

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
        'name' => 'Resources',
        'description' => 'ACCESS resources and their documentation.',
      ],
      [
        'name' => 'Resource Groups',
        'description' => 'Groupings of related resources.',
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
