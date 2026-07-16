<?php

namespace Drupal\access\Plugin\openapi\OpenApiGenerator;

use Drupal\openapi\Plugin\openapi\OpenApiGeneratorBase;
use Symfony\Component\Yaml\Yaml;

/**
 * OpenApi generator for the ACCESS Content API.
 *
 * @OpenApiGenerator(
 *   id = "access_content",
 *   label = @Translation("ACCESS Content API"),
 * )
 */
class AccessContentGenerator extends OpenApiGeneratorBase {

  public function getApiName() { return 'ACCESS Content API'; }

  protected function getApiDescription() {
    return 'Public JSON endpoints exposing ACCESS Support page content and a discovery index for RAG ingestion and Elastic syndication.';
  }

  public function getBasePath() { return '/api/1.0'; }

  public function getSpecification() {
    $spec_file = DRUPAL_ROOT . '/modules/custom/access/openapi/content-api-1.0-openapi.yaml';
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

  public function getPaths() { return []; }

  public function getTags() {
    return [['name' => 'Content', 'description' => 'ACCESS Support page content and discovery index.']];
  }

  public function getProduces() { return ['application/json']; }

  protected function getJsonSchema($described_format, $entity_type_id, $bundle_name = NULL) { return []; }

}
