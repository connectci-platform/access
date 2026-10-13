<?php

namespace Drupal\access\Plugin\openapi\OpenApiGenerator;

use Drupal\openapi\Plugin\openapi\OpenApiGeneratorBase;
use Symfony\Component\Yaml\Yaml;

/**
 * OpenApi generator for the ACCESS KB Resources API.
 *
 * @OpenApiGenerator(
 *   id = "access_kb_resources",
 *   label = @Translation("ACCESS KB Resources API"),
 * )
 */
class AccessKbResourcesGenerator extends OpenApiGeneratorBase {

  /**
   * Gets the API name.
   *
   * @return string
   *   The API name.
   */
  public function getApiName() {
    return 'ACCESS KB Resources API';
  }

  /**
   * Gets the API description.
   *
   * @return string
   *   The API description.
   */
  protected function getApiDescription() {
    return 'Public JSON endpoint exposing ACCESS Knowledge Base resources (CI links) for RAG ingestion and discovery.';
  }

  /**
   * Gets the base path for the API.
   *
   * @return string
   *   The base path.
   */
  public function getBasePath() {
    return '/api/1.0';
  }

  /**
   * Gets the API specification from the YAML file.
   *
   * @return array
   *   The parsed OpenAPI specification.
   */
  public function getSpecification() {
    $spec_file = DRUPAL_ROOT . '/modules/custom/access/openapi/kb-resources-api-1.0-openapi.yaml';
    if (file_exists($spec_file)) {
      $spec = Yaml::parse(file_get_contents($spec_file));
      if (isset($spec['servers'])) {
        $current_server_url = $this->request->getSchemeAndHttpHost() . '/api/1.0';
        $production_url = 'https://support.access-ci.org/api/1.0';
        if ($current_server_url !== $production_url) {
          array_unshift($spec['servers'], ['url' => $current_server_url, 'description' => 'Current server']);
        }
      }
      return $spec;
    }
    return parent::getSpecification();
  }

  /**
   * Gets the API paths.
   *
   * @return array
   *   An empty array (paths are loaded from the YAML file).
   */
  public function getPaths() {
    return [];
  }

  /**
   * Gets the API tags.
   *
   * @return array
   *   An array of tag definitions.
   */
  public function getTags() {
    return [
      [
        'name' => 'KB Resources',
        'description' => 'ACCESS Knowledge Base resources (CI links) listing and discovery.',
      ],
    ];
  }

  /**
   * Gets the API produces media types.
   *
   * @return array
   *   An array of media types.
   */
  public function getProduces() {
    return ['application/json'];
  }

  /**
   * Gets the JSON schema for a specific entity.
   *
   * @param string $described_format
   *   The format to describe.
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle_name
   *   The bundle name (optional).
   *
   * @return array
   *   An empty array (schemas are loaded from the YAML file).
   */
  protected function getJsonSchema($described_format, $entity_type_id, $bundle_name = NULL) {
    return [];
  }

}
