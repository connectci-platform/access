<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\access_content_api\Controller\ContentIndexController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Tests the "collections" key of /.well-known/content-index.json.
 *
 * Runs with access_cilink enabled; ContentIndexTest covers the index without
 * it.
 *
 * @group access_content_api
 * @group access_cilink
 */
class ContentIndexCollectionsTest extends ContentApiKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var array<string>
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'path_alias',
    'layout_builder',
    'layout_discovery',
    'domain',
    'domain_access',
    'access_content_api',
    'taxonomy',
    'webform',
    'flag',
    'access_cilink',
  ];

  /**
   * Region term mapped to the support domain class.
   */
  protected Term $region;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('webform_submission');
    $this->installEntitySchema('flagging');
    $this->installSchema('webform', ['webform']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installConfig(['webform']);

    Vocabulary::create(['vid' => 'region', 'name' => 'region'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_region_connected_domain',
      'entity_type' => 'taxonomy_term',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_region_connected_domain',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'region',
      'label' => 'Connected domain',
    ])->save();
    $this->region = Term::create([
      'vid' => 'region',
      'name' => 'Support region',
      'field_region_connected_domain' => 'access-support',
    ]);
    $this->region->save();

    Webform::create([
      'id' => 'resource',
      'title' => 'Knowledge Base Resources',
      'elements' => <<<YAML
approved:
  '#type': checkbox
private:
  '#type': checkbox
title:
  '#type': textfield
domain:
  '#type': webform_term_select
  '#multiple': true
  '#vocabulary': region
YAML,
    ])->save();
  }

  /**
   * Creates a resource submission with a pinned changed time.
   */
  protected function createResource(bool $approved, bool $private, int $changed): WebformSubmission {
    $submission = WebformSubmission::create([
      'webform_id' => 'resource',
      'data' => [
        'title' => 'Resource',
        'approved' => (int) $approved,
        'private' => (int) $private,
        'domain' => [$this->region->id()],
      ],
    ]);
    $submission->save();
    // ChangedItem stamps the request time on save; pin the stored value so the
    // assertions are deterministic.
    \Drupal::database()->update('webform_submission')
      ->fields(['changed' => $changed])
      ->condition('sid', $submission->id())
      ->execute();
    return $submission;
  }

  /**
   * Returns the decoded index and its response.
   *
   * @return array{0: array<string, mixed>, 1: \Drupal\Core\Cache\CacheableJsonResponse}
   *   The data and the response.
   */
  protected function index(): array {
    $controller = \Drupal::classResolver()->getInstanceFromDefinition(ContentIndexController::class);
    $response = $controller->index();
    return [json_decode($response->getContent(), TRUE), $response];
  }

  /**
   * The index advertises the KB resources collection.
   */
  public function testCollectionEntry(): void {
    $this->createPage(['title' => 'A Page']);
    $this->createResource(TRUE, FALSE, 1700000000);

    [$data, $response] = $this->index();

    $this->assertSame(1, $data['version']);
    $this->assertCount(1, $data['collections']);
    $collection = $data['collections'][0];
    $this->assertSame('kb_resources', $collection['name']);
    $this->assertSame('Knowledge Base resources (CI links): curated external links with category, tags and skill level.', $collection['description']);
    $this->assertSame('https://support.access-ci.org/api/1.0/kb-resources', $collection['url']);
    $this->assertSame('https://support.access-ci.org/openapi/access_kb_resources', $collection['spec_url']);
    $this->assertSame(date('c', 1700000000), $collection['last_modified']);
    $this->assertContains('webform_submission_list:resource', $response->getCacheableMetadata()->getCacheTags());

    // Pages keep their meaning and their keys.
    $this->assertNotEmpty($data['pages']);
    $this->assertEqualsCanonicalizing(
      ['title', 'path', 'content_url', 'last_modified', 'content_hash', 'content_type'],
      array_keys($data['pages'][0])
    );
  }

  /**
   * Unapproved and private submissions do not move last_modified.
   */
  public function testLastModifiedOnlyTracksPublicResources(): void {
    $this->createResource(TRUE, FALSE, 1700000000);
    $this->createResource(TRUE, FALSE, 1700000500);
    // Newer, but not public.
    $this->createResource(FALSE, FALSE, 1800000000);
    $this->createResource(TRUE, TRUE, 1800000100);

    [$data] = $this->index();
    $this->assertSame(date('c', 1700000500), $data['collections'][0]['last_modified']);
  }

  /**
   * An empty public set yields a NULL last_modified.
   */
  public function testLastModifiedNullWithoutPublicResources(): void {
    $this->createResource(FALSE, FALSE, 1700000000);

    [$data] = $this->index();
    $this->assertCount(1, $data['collections']);
    $this->assertNull($data['collections'][0]['last_modified']);
  }

}
