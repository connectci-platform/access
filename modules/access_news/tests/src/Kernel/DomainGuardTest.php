<?php

declare(strict_types=1);

namespace Drupal\Tests\access_news\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * The access_news node presave strip guard.
 *
 * The news form removes the bi-weekly-digest share option from
 * field_choose_where_to_share_this on every domain but ACCESS Support, so an
 * off-domain submission round-trips without it; without a guard the save
 * silently drops the value (D8-2824 — the same class of bug already fixed
 * for eventseries, see access_events's DomainGuardTest). This test pins the
 * entity-layer contract; the form-level option removal is out of kernel
 * reach.
 *
 * @group access_news
 */
class DomainGuardTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    // 'access' provides access.hidden_field_options; it also registers
    // access.eligibility_check_subscriber, which depends on
    // access_affinitygroup.allocations_client.
    'access',
    'access_affinitygroup',
    'key',
    'access_news',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node']);

    NodeType::create(['type' => 'access_news', 'name' => 'Announcement'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_choose_where_to_share_this',
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_choose_where_to_share_this',
      'entity_type' => 'node',
      'bundle' => 'access_news',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_affinity_group_node',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_affinity_group_node',
      'entity_type' => 'node',
      'bundle' => 'access_news',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_affinity_group',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_affinity_group',
      'entity_type' => 'node',
      'bundle' => 'access_news',
    ])->save();

    $this->setCurrentUser($this->createUser());
  }

  /**
   * Registers a stub domain negotiator returning a fixed active domain.
   */
  private function stubActiveDomain(string $id): void {
    $domain = new class($id) {

      public function __construct(private string $id) {}

      /**
       * Returns the stubbed domain ID.
       */
      public function id(): string {
        return $this->id;
      }

    };
    $negotiator = new class($domain) {

      public function __construct(private object $domain) {}

      /**
       * Returns the stubbed active domain.
       */
      public function getActiveDomain(): object {
        return $this->domain;
      }

    };
    \Drupal::getContainer()->set('domain.negotiator', $negotiator);
  }

  /**
   * Reloads a node bypassing static caches.
   */
  private function reloadNode(int $id): Node {
    $node = \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($id);
    assert($node instanceof Node);
    return $node;
  }

  /**
   * The digest option is preserved when edited from a non-support domain.
   *
   * Off the ACCESS Support domain the form hides the digest option, so a
   * save that lacks it round-trips a value the editor never saw; the guard
   * restores it. On the ACCESS Support domain the option is visible, so a
   * removal there sticks.
   */
  public function testStripGuardDigestValueByDomainContext(): void {
    $node = Node::create([
      'type' => 'access_news',
      'title' => 'Test announcement',
      'field_choose_where_to_share_this' => [
        ['value' => 'on_the_announcements_page'],
        ['value' => 'in_the_access_support_bi_weekly_digest'],
      ],
    ]);
    $node->save();

    // Off-support context (option hidden): removal is restored.
    $this->stubActiveDomain('ccmnet_org');
    $node->set('field_choose_where_to_share_this', [['value' => 'on_the_announcements_page']]);
    $node->save();
    $values = array_column($this->reloadNode((int) $node->id())->get('field_choose_where_to_share_this')->getValue(), 'value');
    sort($values);
    $this->assertSame(['in_the_access_support_bi_weekly_digest', 'on_the_announcements_page'], $values);

    // Support context (option visible): removal sticks.
    $this->stubActiveDomain('amp_cyberinfrastructure_org');
    $node = $this->reloadNode((int) $node->id());
    $node->set('field_choose_where_to_share_this', [['value' => 'on_the_announcements_page']]);
    $node->save();
    $this->assertSame(
      ['on_the_announcements_page'],
      array_column($this->reloadNode((int) $node->id())->get('field_choose_where_to_share_this')->getValue(), 'value')
    );
  }

  /**
   * Without a negotiator service, a save with no digest value stays empty.
   *
   * No active domain resolves to "hide" (maximally protective), but with
   * nothing hidden lost there is nothing to restore.
   */
  public function testNoNegotiatorStaysConsistent(): void {
    $node = Node::create([
      'type' => 'access_news',
      'title' => 'Domainless announcement',
      'field_choose_where_to_share_this' => [
        ['value' => 'on_the_announcements_page'],
      ],
    ]);
    $node->save();

    $node->set('title', 'Edited domainless announcement');
    $node->save();

    $this->assertSame(
      ['on_the_announcements_page'],
      array_column($this->reloadNode((int) $node->id())->get('field_choose_where_to_share_this')->getValue(), 'value')
    );
  }

}
