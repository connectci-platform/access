<?php

namespace Drupal\Tests\cssn\Kernel;

use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\flag\Traits\FlagCreateTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\cssn\Plugin\search_api\processor\UserAffinityGroups;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Item\Item;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Tests the UserAffinityGroups search_api processor.
 *
 * @group cssn
 */
class UserAffinityGroupsProcessorTest extends KernelTestBase {

  use FlagCreateTrait;
  use UserCreationTrait;

  /**
   * Modules to enable.
   *
   * Deliberately NOT enabling cssn (heavy install hooks). We test the
   * processor CLASS directly, same pattern as EventRegistrationProcessorTest.
   *
   * @var array
   */
  protected static $modules = [
    'field', 'filter', 'flag', 'taxonomy', 'text', 'user', 'system', 'search_api',
  ];

  /**
   * The processor under test.
   *
   * @var \Drupal\cssn\Plugin\search_api\processor\UserAffinityGroups
   */
  private UserAffinityGroups $processor;

  /**
   * The flag service.
   *
   * @var \Drupal\flag\FlagServiceInterface
   */
  private $flagService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('flagging');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('flag', ['flag_counts']);
    $this->installConfig(['filter', 'flag']);

    $this->flagService = \Drupal::service('flag');

    $this->processor = new UserAffinityGroups([], 'user_affinity_groups', []);
    $this->processor->setFieldsHelper(\Drupal::service('search_api.fields_helper'));
    $property = new \ReflectionProperty(UserAffinityGroups::class, 'database');
    $property->setAccessible(TRUE);
    $property->setValue($this->processor, \Drupal::database());
    $property = new \ReflectionProperty(UserAffinityGroups::class, 'entityTypeManager');
    $property->setAccessible(TRUE);
    $property->setValue($this->processor, \Drupal::entityTypeManager());
  }

  /**
   * Creates (or reuses) a taxonomy term in the affinity_groups vocabulary.
   */
  private function makeTerm(string $name): Term {
    $vocabulary = Vocabulary::load('affinity_groups');
    if (!$vocabulary) {
      $vocabulary = Vocabulary::create(['vid' => 'affinity_groups', 'name' => 'Affinity Groups']);
      $vocabulary->save();
    }
    $term = Term::create(['vid' => 'affinity_groups', 'name' => $name]);
    $term->save();
    return $term;
  }

  /**
   * Creates the affinity_group flag used by the processor's query.
   */
  private function makeFlag() {
    return $this->createFlagFromArray([
      'id' => 'affinity_group',
      'entity_type' => 'taxonomy_term',
      'bundles' => ['affinity_groups'],
      'flag_type' => 'entity:taxonomy_term',
    ]);
  }

  /**
   * Builds a search_api item for the given user and runs the processor on it.
   */
  private function extract($account): array {
    $index = Index::create(['id' => 'test_index']);
    $item = new Item($index, 'entity:user/' . $account->id());
    $item->setOriginalObject(EntityAdapter::createFromEntity($account));

    $field = \Drupal::service('search_api.fields_helper')
      ->createField($index, 'search_api_user_affinity_groups', [
        'property_path' => 'search_api_user_affinity_groups',
        'type' => 'string',
      ]);
    $item->setField('search_api_user_affinity_groups', $field);
    $item->setFieldsExtracted(TRUE);

    $this->processor->addFieldValues($item);

    return $field->getValues();
  }

  /**
   * Tests that a flagged affinity group is indexed for the flagging user.
   */
  public function testFlaggedGroupsIndexForUserA(): void {
    $flag = $this->makeFlag();
    $userA = $this->createUser();
    $term = $this->makeTerm('Neuroscience');
    $this->flagService->flag($flag, $term, $userA);

    $this->assertSame(['Neuroscience'], $this->extract($userA));
  }

  /**
   * Tests that a user with no flaggings gets no indexed values.
   */
  public function testNothingForUserB(): void {
    $this->makeFlag();
    $userB = $this->createUser();

    $this->assertSame([], $this->extract($userB));
  }

  /**
   * Tests that a flagging pointing at a deleted term is skipped, not fatal.
   */
  public function testDeletedTermFlaggingSkipsWithoutException(): void {
    $flag = $this->makeFlag();
    $userA = $this->createUser();
    $term = $this->makeTerm('Genomics');
    $this->flagService->flag($flag, $term, $userA);
    $term->delete();

    $this->assertSame([], $this->extract($userA));
  }

  /**
   * Tests that the processor's plugin ID still resolves after the rewrite.
   */
  public function testPluginIdStillResolves(): void {
    $this->assertSame('user_affinity_groups', $this->processor->getPluginId());
  }

}
