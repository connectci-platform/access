<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Logger\RfcLogLevel;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * Tests the pre-10300 field_inheritance key-value/config cleanup update.
 *
 * @covers access_events_update_10008
 * @covers _access_events_field_inheritance_row_is_dead
 * @group access_events
 */
class FieldInheritanceCleanupUpdateTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The test seeds field_inheritance.config with the legacy pre-3.x
   * `included_bundles` key, which field_inheritance 3.x's schema
   * (field_inheritance.schema.yml) no longer defines — only
   * `included_entities` is. Rather than disabling strict config schema
   * checking entirely, exclude just this one config object from the
   * checker; the point of this test is the update hook's key-value/config
   * behaviour, not schema enforcement of a key the hook itself only ever
   * removes.
   */
  protected static $configSchemaCheckerExclusions = [
    'field_inheritance.config',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // access_events_update_10008() and its helper live in the .install file,
    // which is never autoloaded — Drupal only includes it right before
    // running update hooks. Load it explicitly, the same way update.php
    // does, so the functions are callable here.
    \Drupal::moduleHandler()->loadInclude('access_events', 'install');

    // The hook warns about every non-eventinstance, non-file row it decided
    // to keep, so an unexpected production row surfaces in dslog instead of
    // being silently destroyed. Capture that channel to assert it fires.
    $this->warnings = new \ArrayObject();
    \Drupal::service('logger.factory')->addLogger(new WarningCollector($this->warnings));
  }

  /**
   * Warning-level messages logged during the hook run.
   */
  private \ArrayObject $warnings;

  /**
   * Data provider for testRowIsDead().
   */
  public static function providerRowIsDead(): array {
    return [
      'eventinstance never dead' => [
        'eventinstance:11111111-1111-1111-1111-111111111111',
        ['enabled' => FALSE],
        FALSE,
      ],
      'file excluded this pass' => [
        'file:22222222-2222-2222-2222-222222222222',
        ['enabled' => FALSE],
        FALSE,
      ],
      'node disabled no mappings is dead' => [
        'node:33333333-3333-3333-3333-333333333333',
        ['enabled' => FALSE],
        TRUE,
      ],
      'node disabled (falsy int) no mappings is dead' => [
        'node:44444444-4444-4444-4444-444444444444',
        ['enabled' => 0],
        TRUE,
      ],
      'node enabled is not dead' => [
        'node:55555555-5555-5555-5555-555555555555',
        ['enabled' => TRUE],
        FALSE,
      ],
      'node disabled but with a field mapping is not dead' => [
        'node:66666666-6666-6666-6666-666666666666',
        ['enabled' => FALSE, 'field_x' => ['entity' => 'foo']],
        FALSE,
      ],
      'block_content disabled no mappings is dead' => [
        'block_content:77777777-7777-7777-7777-777777777777',
        ['enabled' => FALSE],
        TRUE,
      ],
      'taxonomy_term enabled is not dead' => [
        'taxonomy_term:88888888-8888-8888-8888-888888888888',
        ['enabled' => TRUE],
        FALSE,
      ],
      'non-array value is never dead' => [
        'node:99999999-9999-9999-9999-999999999999',
        'a scalar, not an array',
        FALSE,
      ],
    ];
  }

  /**
   * Tests the dead-row predicate against each fixture case.
   *
   * @dataProvider providerRowIsDead
   */
  public function testRowIsDead(string $key, $value, bool $expected): void {
    $this->assertSame($expected, _access_events_field_inheritance_row_is_dead($key, $value));
  }

  /**
   * Seeds the legacy pre-upgrade field_inheritance.config + key-value rows.
   *
   * @return string[]
   *   The uuids used, keyed by the fixture label used in the assertions
   *   below: eventinstance, node_dead, node_kept, taxonomy_term,
   *   block_content, file.
   */
  private function seedFixture(): array {
    $uuids = [
      'eventinstance' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
      'node_dead' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
      'node_kept' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
      'taxonomy_term' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
      'block_content' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
      'file' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
    ];

    \Drupal::configFactory()->getEditable('field_inheritance.config')
      ->set('included_entities', 'block_content,eventinstance,file,node,taxonomy_term')
      ->set('included_bundles', 'block_content:basic,eventinstance:default,file:file,node:article,node:page,taxonomy_term:tags')
      ->save();

    $key_value = \Drupal::keyValue('field_inheritance');
    $key_value->setMultiple([
      'eventinstance:' . $uuids['eventinstance'] => [
        'enabled' => TRUE,
        'field_title' => ['entity' => 'eventseries'],
      ],
      'node:' . $uuids['node_dead'] => [
        'enabled' => FALSE,
      ],
      'node:' . $uuids['node_kept'] => [
        'enabled' => FALSE,
        'field_body' => ['entity' => 'node'],
      ],
      'taxonomy_term:' . $uuids['taxonomy_term'] => [
        'enabled' => TRUE,
      ],
      'block_content:' . $uuids['block_content'] => [
        'enabled' => FALSE,
      ],
      'file:' . $uuids['file'] => [
        'enabled' => FALSE,
      ],
    ]);

    return $uuids;
  }

  /**
   * Runs the hook end to end and asserts what it deletes, keeps, and returns.
   */
  public function testUpdate10008DeletesDeadRowsAndTrimsConfig(): void {
    $uuids = $this->seedFixture();
    $key_value = \Drupal::keyValue('field_inheritance');
    $beforeAll = $key_value->getAll();

    $result = access_events_update_10008();

    $expectedDeletedKeys = [
      'node:' . $uuids['node_dead'],
      'block_content:' . $uuids['block_content'],
    ];

    $afterAll = $key_value->getAll();

    foreach ($expectedDeletedKeys as $deletedKey) {
      $this->assertArrayNotHasKey($deletedKey, $afterAll, "$deletedKey should have been deleted.");
    }

    $expectedSurvivingKeys = array_diff(array_keys($beforeAll), $expectedDeletedKeys);
    $this->assertCount(count($expectedSurvivingKeys), $afterAll, 'Only the two dead rows were deleted.');
    foreach ($expectedSurvivingKeys as $survivingKey) {
      $this->assertArrayHasKey($survivingKey, $afterAll, "$survivingKey should have survived.");
      $this->assertSame($beforeAll[$survivingKey], $afterAll[$survivingKey], "$survivingKey's value should be unchanged.");
    }

    $config = \Drupal::config('field_inheritance.config');
    $this->assertSame(['eventinstance'], $config->get('included_entities'));
    $this->assertSame('eventinstance:default', $config->get('included_bundles'));

    $this->assertNotSame('', (string) $result);

    // Every non-eventinstance, non-file row that survived is warned about,
    // and nothing that was deleted or kept by design is.
    $warned = implode("\n", $this->warnings->getArrayCopy());
    $this->assertStringContainsString('node:' . $uuids['node_kept'], $warned);
    $this->assertStringContainsString('taxonomy_term:' . $uuids['taxonomy_term'], $warned);
    $this->assertStringNotContainsString('eventinstance:' . $uuids['eventinstance'], $warned);
    $this->assertStringNotContainsString('file:' . $uuids['file'], $warned);
    $this->assertStringNotContainsString('node:' . $uuids['node_dead'], $warned);
    $this->assertStringNotContainsString('block_content:' . $uuids['block_content'], $warned);

    // Idempotence: running the hook again deletes nothing further and leaves
    // config unchanged.
    $secondResult = access_events_update_10008();
    $afterSecond = $key_value->getAll();
    $this->assertSame($afterAll, $afterSecond, 'A second run deletes nothing further.');

    $configAfterSecond = \Drupal::config('field_inheritance.config');
    $this->assertSame(['eventinstance'], $configAfterSecond->get('included_entities'));
    $this->assertSame('eventinstance:default', $configAfterSecond->get('included_bundles'));
    $this->assertNotSame('', (string) $secondResult);
  }

}

/**
 * Collects warning-level log messages for assertion.
 *
 * Drupal's LoggerChannel translates the PSR level to an RfcLogLevel integer
 * before it reaches the registered loggers, so both forms are accepted.
 */
class WarningCollector implements LoggerInterface {

  use LoggerTrait;

  /**
   * Constructs a WarningCollector.
   *
   * @param \ArrayObject $messages
   *   Shared storage the test reads back.
   */
  public function __construct(private readonly \ArrayObject $messages) {}

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    if ($level !== RfcLogLevel::WARNING && $level !== 'warning') {
      return;
    }
    $placeholders = array_filter(
      $context,
      static fn (string $key): bool => str_starts_with($key, '@'),
      ARRAY_FILTER_USE_KEY
    );
    $this->messages[] = strtr((string) $message, $placeholders);
  }

}
