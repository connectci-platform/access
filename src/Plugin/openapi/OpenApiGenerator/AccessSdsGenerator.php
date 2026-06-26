<?php

namespace Drupal\access\Plugin\openapi\OpenApiGenerator;

use Drupal\openapi\Plugin\openapi\OpenApiGeneratorBase;
use Symfony\Component\Yaml\Yaml;

/**
 * OpenApi generator for the ACCESS SDS (Software Discovery) API.
 *
 * @OpenApiGenerator(
 *   id = "access_sds",
 *   label = @Translation("ACCESS SDS (Software Discovery) API"),
 * )
 */
class AccessSdsGenerator extends OpenApiGeneratorBase {

  public function getApiName() { return 'ACCESS SDS (Software Discovery) API'; }

  protected function getApiDescription() {
    return 'Software-on-resources discovery. Hosted externally by the University of Kentucky at sds-ara-api.access-ci.org and requires an API key.';
  }

  public function getBasePath() { return '/api/v1'; }

  public function getSpecification() {
    $spec_file = DRUPAL_ROOT . '/modules/custom/access/openapi/sds-api-openapi.yaml';
    if (file_exists($spec_file)) {
      // External API: do NOT inject a dev/prod server; servers[] is UKy-only.
      return Yaml::parse(file_get_contents($spec_file));
    }
    return parent::getSpecification();
  }

  public function getPaths() { return []; }

  public function getTags() {
    return [['name' => 'Software Discovery', 'description' => 'SDS software-on-resources queries (external, UKy-hosted).']];
  }

  public function getProduces() { return ['application/json']; }

  protected function getJsonSchema($described_format, $entity_type_id, $bundle_name = NULL) { return []; }

}
