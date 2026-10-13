<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\access_affinitygroup\Plugin\ConstantContactApi;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\taxonomy\Entity\Vocabulary;
use Psr\Log\LoggerInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests the Constant Contact list sync on affinity group save (D8-2857).
 *
 * @group access_affinitygroup
 */
class AffinityGroupCcListSyncTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'options',
    'text',
    'filter',
    'taxonomy',
    'workflows',
    'content_moderation',
    'access',
    'access_affinitygroup',
    'access_news',
    'key',
  ];

  /**
   * Collected log records.
   *
   * @var array
   */
  protected array $logs = [];

  /**
   * Ordered record of API client interactions.
   *
   * @var array
   */
  protected array $events = [];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('access.access_id_resolver', 'Drupal\access\AccessIdResolver')
      ->addArgument(new Reference('entity_type.manager'))
      ->addArgument(new Reference('database'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter']);

    NodeType::create([
      'type' => 'affinity_group',
      'name' => 'Affinity Group',
    ])->save();

    $this->addField('body', 'text_with_summary');
    $this->addField('field_list_id', 'string');

    $logs = &$this->logs;
    $logger = new class($logs) implements LoggerInterface {

      use RfcLoggerTrait;

      /**
       * Constructs the collector.
       *
       * @param array $logs
       *   Reference to the collected records.
       */
      public function __construct(protected array &$logs) {
      }

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->logs[] = [
          'level' => (int) $level,
          'message' => (string) $message,
          'context' => $context,
        ];
      }

    };
    $this->container->get('logger.factory')->addLogger($logger);
  }

  /**
   * Creates a node field on affinity_group.
   */
  protected function addField(string $name, string $type, array $storage_settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'cardinality' => 1,
    ] + ($storage_settings ? ['settings' => $storage_settings] : []))->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
    ])->save();
  }

  /**
   * Builds an unsaved affinity group node.
   */
  protected function buildNode(string $title, ?string $listId = NULL, $body = NULL): Node {
    $values = ['type' => 'affinity_group', 'title' => $title];
    if ($listId !== NULL) {
      $values['field_list_id'] = $listId;
    }
    if ($body !== NULL) {
      $values['body'] = $body;
    }
    return Node::create($values);
  }

  /**
   * Builds a mock API client with canned responses.
   *
   * @param array $routes
   *   Keyed by "METHOD /path": [response object or NULL, HTTP code].
   *   Missing routes fail the test.
   */
  protected function buildApi(array $routes): ConstantContactApi {
    $last_code = NULL;
    $events = &$this->events;
    $mock = $this->createMock(ConstantContactApi::class);
    $mock->method('apiCall')->willReturnCallback(
      function ($endpoint, $data = NULL, $method = 'GET') use ($routes, &$last_code, &$events) {
        $key = "$method $endpoint";
        $events[] = ['call', $key, $data];
        if (!array_key_exists($key, $routes)) {
          $this->fail("Unexpected API call: $key");
        }
        [$response, $last_code] = $routes[$key];
        return $response;
      }
    );
    $mock->method('getResponseCode')->willReturnCallback(function () use (&$last_code) {
      return $last_code;
    });
    $mock->method('getSupressErrDisplay')->willReturn(FALSE);
    $mock->method('setSupressErrDisplay')->willReturnCallback(
      function ($v) use (&$events) {
        $events[] = ['suppress', $v];
      }
    );
    return $mock;
  }

  /**
   * Returns the keys of API calls made so far.
   */
  protected function calls(): array {
    $keys = [];
    foreach ($this->events as $event) {
      if ($event[0] === 'call') {
        $keys[] = $event[1];
      }
    }
    return $keys;
  }

  /**
   * Returns the data posted for a call key, decoded.
   */
  protected function payload(string $key): ?array {
    foreach ($this->events as $event) {
      if ($event[0] === 'call' && $event[1] === $key) {
        return json_decode($event[2], TRUE);
      }
    }
    return NULL;
  }

  /**
   * Returns status messages.
   */
  protected function statusMessages(): array {
    return array_map('strval', \Drupal::messenger()->messagesByType('status'));
  }

  /**
   * Returns logs at a given RFC level.
   */
  protected function logsAt(int $level): array {
    return array_values(array_filter($this->logs, fn($l) => $l['level'] === $level));
  }

  /**
   * Renders a log record message with its context placeholders.
   */
  protected function renderLog(array $log): string {
    return strtr($log['message'], array_filter($log['context'], 'is_scalar'));
  }

  /**
   * A 404 on the stored list id creates a new list without error display.
   */
  public function testStoredList404CreatesNewList(): void {
    $node = $this->buildNode('My Group', 'old-id');
    $api = $this->buildApi([
      'GET /contact_lists/old-id' => [NULL, 404],
      'GET /contact_lists' => [(object) ['lists' => [(object) ['name' => 'Other', 'list_id' => 'other-id']]], 200],
      'POST /contact_lists' => [(object) ['list_id' => 'new-id'], 201],
    ]);
    \Drupal::messenger()->deleteAll();

    access_affinitygroup_sync_cc_list($node, $api);

    $this->assertSame('new-id', $node->get('field_list_id')->value, 'field_list_id updated to the new list id.');
    $this->assertEmpty(\Drupal::messenger()->messagesByType('error'), 'No error messages shown to the editor.');
    $status = $this->statusMessages();
    $this->assertCount(1, $status, 'Exactly one status message.');
    $this->assertStringContainsString('new-id', $status[0]);
    $this->assertSame(
      [
        ['suppress', TRUE],
        ['call', 'GET /contact_lists/old-id', NULL],
        ['suppress', FALSE],
      ],
      array_slice($this->events, 0, 3),
      'Suppression enabled before the GET and restored afterward.'
    );
    $this->assertSame(
      ['GET /contact_lists/old-id', 'GET /contact_lists', 'POST /contact_lists'],
      $this->calls()
    );
    $notices = $this->logsAt(5);
    $this->assertCount(1, $notices, 'One notice-level log entry.');
    $this->assertStringContainsString('old-id', $this->renderLog($notices[0]));
  }

  /**
   * A 404 relinks to an existing list with the same name.
   */
  public function testStoredList404RelinksExistingByName(): void {
    $node = $this->buildNode('My Group', 'old-id');
    $api = $this->buildApi([
      'GET /contact_lists/old-id' => [NULL, 404],
      'GET /contact_lists' => [(object) [
        'lists' => [
          (object) ['name' => 'Other', 'list_id' => 'other-id'],
          (object) ['name' => 'My Group', 'list_id' => 'existing-id'],
        ],
      ], 200,
      ],
    ]);
    \Drupal::messenger()->deleteAll();

    access_affinitygroup_sync_cc_list($node, $api);

    $this->assertSame('existing-id', $node->get('field_list_id')->value);
    $this->assertNotContains('POST /contact_lists', $this->calls(), 'No list was created.');
    $status = $this->statusMessages();
    $this->assertCount(1, $status);
    $this->assertStringContainsString('relinked', $status[0]);
    $this->assertEmpty(\Drupal::messenger()->messagesByType('error'));
  }

  /**
   * A non-404 failure aborts without creating a duplicate.
   */
  public function testNon404AbortsWithoutCreate(): void {
    $node = $this->buildNode('My Group', 'old-id');
    $api = $this->buildApi([
      'GET /contact_lists/old-id' => [NULL, 500],
    ]);
    \Drupal::messenger()->deleteAll();

    access_affinitygroup_sync_cc_list($node, $api);

    $this->assertSame(['GET /contact_lists/old-id'], $this->calls(), 'Only one API call made.');
    $this->assertSame('old-id', $node->get('field_list_id')->value, 'field_list_id unchanged.');
    $this->assertCount(1, \Drupal::messenger()->messagesByType('error'), 'One error message added.');
    $errors = $this->logsAt(3);
    $this->assertCount(1, $errors, 'One error-level log entry.');
    $this->assertStringContainsString('old-id', $this->renderLog($errors[0]));
    $this->assertStringContainsString('500', $this->renderLog($errors[0]));
  }

  /**
   * An existing list with an outdated name is renamed in place.
   */
  public function testStoredListExistsRenamesInPlace(): void {
    $node = $this->buildNode('New title', 'old-id');
    $api = $this->buildApi([
      'GET /contact_lists/old-id' => [(object) ['name' => 'Old title', 'favorite' => FALSE], 200],
      'PUT /contact_lists/old-id' => [(object) ['list_id' => 'old-id', 'name' => 'New title'], 200],
    ]);
    \Drupal::messenger()->deleteAll();

    access_affinitygroup_sync_cc_list($node, $api);

    $this->assertSame(['GET /contact_lists/old-id', 'PUT /contact_lists/old-id'], $this->calls());
    $this->assertSame('New title', $this->payload('PUT /contact_lists/old-id')['name']);
    $this->assertSame('old-id', $node->get('field_list_id')->value);
    $this->assertSame([], $this->statusMessages(), 'No status messages.');
  }

  /**
   * Missing or empty summary must not trigger deprecations.
   *
   * @dataProvider emptySummaryProvider
   */
  public function testEmptySummaryNoDeprecation($body): void {
    $node = $this->buildNode('My Group', NULL, $body);
    $api = $this->buildApi([
      'GET /contact_lists' => [(object) ['lists' => []], 200],
      'POST /contact_lists' => [(object) ['list_id' => 'new-id'], 201],
    ]);
    $deprecations = [];
    set_error_handler(function ($errno, $errstr) use (&$deprecations) {
      $deprecations[] = $errstr;
      return TRUE;
    }, E_DEPRECATED | E_USER_DEPRECATED);
    try {
      access_affinitygroup_sync_cc_list($node, $api);
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame([], $deprecations, 'No deprecations raised.');
    $this->assertSame('', $this->payload('POST /contact_lists')['description']);
    $this->assertSame('new-id', $node->get('field_list_id')->value);
  }

  /**
   * Data provider for testEmptySummaryNoDeprecation.
   */
  public static function emptySummaryProvider(): array {
    return [
      'body without summary' => [['value' => 'Text', 'format' => 'plain_text']],
      'body empty' => [NULL],
    ];
  }

  /**
   * A long multibyte summary is truncated to 255 characters, validly.
   */
  public function testLongSummaryTruncated(): void {
    $node = $this->buildNode('My Group', NULL, [
      'value' => 'Text',
      'summary' => str_repeat('é', 300),
      'format' => 'plain_text',
    ]);
    $api = $this->buildApi([
      'GET /contact_lists' => [(object) ['lists' => []], 200],
      'POST /contact_lists' => [(object) ['list_id' => 'new-id'], 201],
    ]);

    access_affinitygroup_sync_cc_list($node, $api);

    $description = $this->payload('POST /contact_lists')['description'];
    $this->assertSame(255, mb_strlen($description));
    $this->assertTrue(mb_check_encoding($description, 'UTF-8'), 'Truncated description is valid UTF-8.');
  }

  /**
   * A create response without list_id leaves the field empty.
   */
  public function testBadListIdFromCreate(): void {
    $node = $this->buildNode('My Group');
    $api = $this->buildApi([
      'GET /contact_lists' => [(object) ['lists' => []], 200],
      'POST /contact_lists' => [(object) ['something' => 'else'], 201],
    ]);
    \Drupal::messenger()->deleteAll();

    access_affinitygroup_sync_cc_list($node, $api);

    $this->assertTrue($node->get('field_list_id')->isEmpty(), 'field_list_id stays empty.');
    $this->assertSame(['Bad list id received from Constant Contact.'], $this->statusMessages());
  }

  /**
   * Invalid list id logging names matched groups and unmatched ids.
   */
  public function testLogInvalidListIds(): void {
    $this->setUpSavableAffinityGroups();
    $one = Node::create([
      'type' => 'affinity_group',
      'title' => 'Group One',
      'field_group_slug' => 'one',
      'field_use_ext_email_list' => 0,
      'field_list_id' => 'list-1',
    ]);
    $one->save();
    $two = Node::create([
      'type' => 'affinity_group',
      'title' => 'Group Two',
      'field_group_slug' => 'two',
      'field_use_ext_email_list' => 0,
      'field_list_id' => 'list-2',
    ]);
    $two->save();
    $this->logs = [];

    access_affinitygroup_log_invalid_list_ids(['list-1', 'list-2', 'ghost-id']);

    $warnings = $this->logsAt(4);
    $this->assertCount(1, $warnings, 'Exactly one warning log.');
    $message = $this->renderLog($warnings[0]);
    $groups = $warnings[0]['context']['@groups'];
    $this->assertStringContainsString('"Group One" (nid ' . $one->id() . ', list-1)', $groups);
    $this->assertStringContainsString('"Group Two" (nid ' . $two->id() . ', list-2)', $groups);
    $this->assertStringContainsString('unmatched list ids: ghost-id', $groups);
    $this->assertStringContainsString('invalid contact list ids', $message);

    $this->logs = [];
    access_affinitygroup_log_invalid_list_ids([]);
    $this->assertSame([], $this->logs, 'Empty list ids log nothing.');
  }

  /**
   * Provides the minimum fixture for saving affinity groups.
   *
   * Access_affinitygroup_entity_presave() Case 2 reads these fields and needs
   * the leader role and vocabularies. Constant Contact calls are disabled.
   */
  protected function setUpSavableAffinityGroups(): void {
    // noConstantContactCalls is left unset: isCCEnabled() then returns FALSE
    // (the module ships no config schema, so it cannot be saved here).
    Role::create(['id' => 'affinity_group_leader', 'label' => 'AG Leader'])->save();
    Vocabulary::create(['vid' => 'affinity_groups', 'name' => 'Affinity Groups'])->save();
    Vocabulary::create(['vid' => 'affinity-group', 'name' => 'Affinity Group'])->save();
    $this->addField('field_group_slug', 'string');
    $this->addField('field_use_ext_email_list', 'boolean');
    $this->addField('field_ext_email_list', 'string');
    FieldStorageConfig::create([
      'field_name' => 'field_coordinator',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_coordinator',
      'entity_type' => 'node',
      'bundle' => 'affinity_group',
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
      'bundle' => 'affinity_group',
      'settings' => ['handler_settings' => ['target_bundles' => ['affinity-group' => 'affinity-group']]],
    ])->save();
  }

}
