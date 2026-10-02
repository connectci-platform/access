<?php

namespace Drupal\Tests\cssn\Kernel;

use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\cssn\Plugin\search_api\processor\UserAffinityGroups;
use Drupal\cssn\Plugin\search_api\processor\UserBadges;
use Drupal\cssn\Plugin\search_api\processor\UserInterests;
use Drupal\cssn\Plugin\search_api\processor\UserSkills;
use Drupal\cssn\Plugin\search_api\processor\UserSkillsId;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Item\Item;
use Drupal\search_api\Processor\ProcessorPluginBase;

/**
 * Tests the user-only gating of the cssn user search_api processors.
 *
 * @group cssn
 */
class UserProcessorSupportsIndexTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * Deliberately NOT enabling cssn (heavy install hooks).
   *
   * @var array
   */
  protected static $modules = [
    'entity_test', 'field', 'user', 'system', 'search_api',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
  }

  /**
   * Provides the processor class names and the property path each one fills.
   *
   * @return array<string, array{0: class-string, 1: string}>
   *   Processor class name and its search_api property path.
   */
  public static function processorProvider(): array {
    return [
      'affinity groups' => [UserAffinityGroups::class, 'search_api_user_affinity_groups'],
      'badges' => [UserBadges::class, 'search_api_user_badges'],
      'interests' => [UserInterests::class, 'search_api_user_interest'],
      'skills' => [UserSkills::class, 'search_api_user_skills'],
      'skills id' => [UserSkillsId::class, 'search_api_user_skills_id'],
    ];
  }

  /**
   * Creates an index with the given datasources.
   *
   * @param string[] $datasource_ids
   *   The datasource plugin IDs.
   */
  private function createIndex(array $datasource_ids): Index {
    return Index::create([
      'id' => 'test_index',
      'datasource_settings' => array_fill_keys($datasource_ids, []),
    ]);
  }

  /**
   * Tests supportsIndex() for user, non-user and mixed indexes.
   *
   * @dataProvider processorProvider
   */
  public function testSupportsIndex(string $class): void {
    $this->assertTrue($class::supportsIndex($this->createIndex(['entity:user'])));
    $this->assertFalse($class::supportsIndex($this->createIndex(['entity:entity_test'])));
    $this->assertTrue($class::supportsIndex($this->createIndex(['entity:entity_test', 'entity:user'])));
  }

  /**
   * Tests that the annotations are no longer locked but remain hidden.
   *
   * @dataProvider processorProvider
   */
  public function testAnnotationNotLockedButHidden(string $class): void {
    $doc = (new \ReflectionClass($class))->getDocComment();
    $this->assertIsString($doc);
    $this->assertDoesNotMatchRegularExpression('/locked\s*=\s*true/i', $doc);
    $this->assertMatchesRegularExpression('/hidden\s*=\s*true/i', $doc);
  }

  /**
   * Tests that a non-user item produces no values and does not throw.
   *
   * @dataProvider processorProvider
   */
  public function testNonUserItemProducesNoValues(string $class, string $property_path): void {
    /** @var \Drupal\search_api\Processor\ProcessorPluginBase $processor */
    $processor = new $class([], 'test_processor', []);
    $this->assertInstanceOf(ProcessorPluginBase::class, $processor);
    $processor->setFieldsHelper(\Drupal::service('search_api.fields_helper'));
    foreach ([
      'database' => \Drupal::database(),
      'entityTypeManager' => \Drupal::entityTypeManager(),
      'fileUrlGenerator' => \Drupal::service('file_url_generator'),
    ] as $name => $value) {
      if (property_exists($class, $name)) {
        $property = new \ReflectionProperty($class, $name);
        $property->setValue($processor, $value);
      }
    }

    $entity = EntityTest::create(['name' => 'Test']);
    $entity->save();

    $index = $this->createIndex(['entity:entity_test', 'entity:user']);
    $item = new Item($index, 'entity:entity_test/' . $entity->id());
    $item->setOriginalObject(EntityAdapter::createFromEntity($entity));
    $field = \Drupal::service('search_api.fields_helper')
      ->createField($index, $property_path, [
        'property_path' => $property_path,
        'type' => 'string',
      ]);
    $item->setField($property_path, $field);
    $item->setFieldsExtracted(TRUE);

    $processor->addFieldValues($item);

    $this->assertSame([], $field->getValues());
  }

}
