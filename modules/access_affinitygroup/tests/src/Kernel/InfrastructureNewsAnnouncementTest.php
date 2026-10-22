<?php

declare(strict_types=1);

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Psr\Log\LoggerInterface;

/**
 * Infrastructure news is emailed to affinity groups for affected resources.
 *
 * An outage that had already ended when it was first imported is not emailed.
 * When the feed import was down, the backlog would otherwise have announced a
 * resolved outage a day late on the first successful import. A resolution
 * posted to an outage that was already imported is an update, and still
 * sends.
 *
 * Constant Contact is disabled in kernel tests, so emailToAffinityGroups()
 * logs its broadcast notice and then stops short of any API call. That notice
 * is what these tests count.
 *
 * @covers ::access_affinitygroup_entity_insert
 * @covers ::access_affinitygroup_entity_update
 * @group access_affinitygroup
 */
class InfrastructureNewsAnnouncementTest extends KernelTestBase implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'datetime',
    'taxonomy',
    'access',
    'access_affinitygroup',
    'key',
  ];

  /**
   * Broadcast notices logged by emailToAffinityGroups().
   *
   * @var string[]
   */
  protected array $broadcasts = [];

  /**
   * The resource the affinity group is linked to.
   */
  protected NodeInterface $resource;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'user']);

    NodeType::create(['type' => 'access_active_resources_from_cid', 'name' => 'Resource'])->save();
    NodeType::create(['type' => 'infrastructure_news', 'name' => 'Infrastructure News'])->save();
    NodeType::create(['type' => 'affinity_group', 'name' => 'Affinity Group'])->save();

    $this->addField('infrastructure_news', 'body', 'text_with_summary');
    $this->addField('infrastructure_news', 'field_do_email_subscribers', 'boolean');
    $this->addField('infrastructure_news', 'field_infrastructure_news_end', 'datetime', 1, ['datetime_type' => 'datetime']);
    $this->addField('infrastructure_news', 'field_affected_infrastructure', 'entity_reference', -1, ['target_type' => 'node']);
    $this->addField('affinity_group', 'field_cider_resources', 'entity_reference', -1, ['target_type' => 'node']);

    // access_affinitygroup_entity_presave() fires on every affinity_group save
    // and needs the affinity_group_leader role + affinity_groups vocab + a few
    // fields to complete without erroring (CC disabled by default).
    Role::create(['id' => 'affinity_group_leader', 'label' => 'AG Leader'])->save();
    Vocabulary::create(['vid' => 'affinity_groups', 'name' => 'Affinity Groups'])->save();
    $this->addField('affinity_group', 'field_use_ext_email_list', 'boolean');
    foreach (['field_group_slug', 'field_ext_email_list', 'field_list_id'] as $agString) {
      $this->addField('affinity_group', $agString, 'string');
    }
    $this->addField('affinity_group', 'field_affinity_group', 'entity_reference', 1, ['target_type' => 'taxonomy_term']);
    $this->addField('affinity_group', 'field_coordinator', 'entity_reference', -1, ['target_type' => 'user']);
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    $this->resource = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Anvil',
    ]);
    $this->resource->save();
    Node::create([
      'type' => 'affinity_group',
      'title' => 'Anvil',
      'status' => 1,
      'field_group_slug' => 'anvil',
      'field_use_ext_email_list' => 0,
      'field_list_id' => 'cc-list-anvil',
      'field_cider_resources' => [$this->resource->id()],
    ])->save();

    // EmailWrapper picks its colors from the domain, through access_misc. Stub
    // that one call rather than enable access_misc and its dependencies.
    $this->container->set('access_misc.sitetools', new class() {

      /**
       * Returns the domain class EmailWrapper styles the email for.
       */
      public function getDomain(): string {
        return 'support';
      }

    });
    $this->container->get('logger.factory')->addLogger($this);
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    if (str_starts_with((string) $message, 'Email broadcast initiated')) {
      $this->broadcasts[] = $context['@title'] ?? '';
    }
  }

  /**
   * Adds a field to a node bundle.
   */
  protected function addField(string $bundle, string $name, string $type, int $cardinality = 1, array $settings = []): void {
    if (!FieldStorageConfig::loadByName('node', $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'cardinality' => $cardinality,
        'settings' => $settings,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
    ])->save();
  }

  /**
   * Creates an infrastructure news item affecting the linked resource.
   *
   * @param string|null $end
   *   The outage end in storage format, or NULL for no end.
   */
  protected function createOutage(?string $end): NodeInterface {
    $outage = Node::create([
      'type' => 'infrastructure_news',
      'title' => 'Anvil outage',
      'body' => 'Power shutdown.',
      'field_do_email_subscribers' => 1,
      'field_infrastructure_news_end' => $end,
      'field_affected_infrastructure' => [$this->resource->id()],
    ]);
    $outage->save();
    return $outage;
  }

  /**
   * An outage that ended before it was first imported is not emailed.
   */
  public function testOutageEndedBeforeImportIsNotEmailed(): void {
    $this->createOutage('2020-01-01T00:00:00');
    $this->assertSame([], $this->broadcasts);
  }

  /**
   * An outage still in progress when imported is emailed.
   */
  public function testCurrentOutageIsEmailed(): void {
    $this->createOutage('2099-01-01T00:00:00');
    $this->assertSame(['Anvil outage'], $this->broadcasts);
  }

  /**
   * An outage with no end time is emailed.
   */
  public function testOutageWithoutEndIsEmailed(): void {
    $this->createOutage(NULL);
    $this->assertSame(['Anvil outage'], $this->broadcasts);
  }

  /**
   * A resolution posted to an outage already imported is still emailed.
   */
  public function testResolutionUpdateIsEmailed(): void {
    $outage = $this->createOutage('2099-01-01T00:00:00');
    $outage->set('title', '[Resolved] Anvil outage');
    $outage->set('field_infrastructure_news_end', '2020-01-01T00:00:00');
    $outage->save();
    $this->assertSame(['Anvil outage', '[Resolved] Anvil outage'], $this->broadcasts);
  }

}
