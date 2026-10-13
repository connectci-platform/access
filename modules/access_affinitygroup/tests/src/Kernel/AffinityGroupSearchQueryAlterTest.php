<?php

declare(strict_types=1);

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;

/**
 * Tests the Search API query alter for the affinity group index.
 *
 * The /affinity-groups view hides private groups from users who are not the
 * coordinator or a listed private user. Because the filter depends on the
 * current user, the query must carry the "user" cache context; without it one
 * user's filtered results were cached and served to everyone, leaking private
 * group titles (D8-2887). Administrators see everything, so they get the
 * cache context but no filter. Other indexes must be left untouched.
 *
 * @covers ::access_affinitygroup_search_api_query_alter
 * @group access_affinitygroup
 */
class AffinityGroupSearchQueryAlterTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'search_api',
    'access',
    'access_affinitygroup',
    'key',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['user', 'search_api']);
    // Burn uid 1 so the test users are not the super user.
    $this->createUser();
  }

  /**
   * Builds a query on an index with the given id and runs preExecute().
   */
  protected function runQuery(string $index_id) {
    $index = Index::create(['id' => $index_id, 'name' => $index_id]);
    $query = $index->query();
    $query->preExecute();
    return $query;
  }

  /**
   * Returns the nested OR condition groups of a query.
   *
   * @return \Drupal\search_api\Query\ConditionGroupInterface[]
   *   The nested groups.
   */
  protected function nestedGroups($query): array {
    return array_values(array_filter(
      $query->getConditionGroup()->getConditions(),
      fn ($condition) => $condition instanceof ConditionGroupInterface,
    ));
  }

  /**
   * A non-admin gets the user cache context and the private-group filter.
   */
  public function testNonAdminIsFiltered(): void {
    $user = $this->createUser();
    $this->setCurrentUser($user);

    $query = $this->runQuery('affinity_groups');

    $this->assertContains('user', $query->getCacheContexts());
    $groups = $this->nestedGroups($query);
    $this->assertCount(1, $groups);
    $this->assertSame('OR', $groups[0]->getConjunction());
    $actual = [];
    foreach ($groups[0]->getConditions() as $condition) {
      $actual[$condition->getField()] = $condition->getValue();
    }
    $this->assertEquals([
      'field_ag_private' => 0,
      'field_coordinator' => $user->id(),
      'field_ag_private_users' => $user->id(),
    ], $actual);
  }

  /**
   * An administrator gets the cache context but no filter.
   */
  public function testAdministratorIsNotFiltered(): void {
    Role::create(['id' => 'administrator', 'label' => 'Administrator'])->save();
    $user = $this->createUser();
    $user->addRole('administrator');
    $user->save();
    $this->setCurrentUser($user);

    $query = $this->runQuery('affinity_groups');

    $this->assertContains('user', $query->getCacheContexts());
    $this->assertSame([], $query->getConditionGroup()->getConditions());
  }

  /**
   * Queries on other indexes are not altered.
   */
  public function testOtherIndexIsUntouched(): void {
    $this->setCurrentUser($this->createUser());

    $query = $this->runQuery('other_index');

    $this->assertNotContains('user', $query->getCacheContexts());
    $this->assertSame([], $query->getConditionGroup()->getConditions());
  }

}
