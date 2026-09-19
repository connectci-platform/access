<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

/**
 * Pins the clone-on-read contract for the shared computed date object.
 *
 * Core's DateTimeComputed caches the DrupalDateTime it builds and returns the
 * SAME object to every reader, so any caller doing
 * `$entity->date->start_date->setTimezone(...)` rewrites the entity's cached
 * value in place and every later read in that request sees the converted
 * object instead of the stored UTC one.
 *
 * Core's aliasing is not ours to change; the contract is that OUR call sites
 * clone before converting. These tests pin that contract — the aliasing
 * itself, and the clone idiom the display paths use.
 *
 * @group access_events
 */
class SharedDateObjectMutationTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The admin list builder formats through date_format config entities that
   * ship in system's config/install and so are absent here, and through the
   * recurring_events instance settings that name one.
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['short' => 'm/d/Y - H:i', 'medium' => 'D, m/d/Y - H:i'] as $id => $pattern) {
      if (!\Drupal::entityTypeManager()->getStorage('date_format')->load($id)) {
        \Drupal::entityTypeManager()->getStorage('date_format')->create([
          'id' => $id,
          'label' => ucfirst($id),
          'locked' => TRUE,
          'pattern' => $pattern,
        ])->save();
      }
    }
    $this->config('recurring_events.eventinstance.config')
      ->set('date_format', 'medium')
      ->save();
  }

  /**
   * Building an admin list row leaves the entity's date object in UTC.
   *
   * EventInstanceListBuilder::buildRow() is the reachable seam for the clone
   * the contrib patch adds. It formats a date in the viewer's zone, and before
   * the patch it did so by mutating the entity's own cached object — so every
   * later read in that request saw the converted value. Dropping the clone
   * makes this fail.
   */
  public function testBuildingAListRowDoesNotConvertTheEntitysDate(): void {
    $instance = $this->createRegistrableInstance();
    $stored = $instance->get('date')->value;

    $builder = \Drupal::entityTypeManager()->getListBuilder('eventinstance');
    $row = $builder->buildRow($instance);

    $this->assertNotEmpty($row['date'] ?? NULL, 'the row rendered a date');
    $this->assertSame($stored, $instance->get('date')->value,
      'the stored column is untouched');
    $this->assertSame('UTC',
      $instance->get('date')->start_date->getTimezone()->getName(),
      "the entity's cached date object is still UTC after the row was built");
  }

  /**
   * Two rows built in sequence do not contaminate each other.
   *
   * Without the clone the first row's conversion persists on the shared
   * object, so the second row formats an already-converted value — the
   * cross-contamination the patch exists to prevent.
   */
  public function testBuildingTwoRowsInSequenceGivesTheSameResult(): void {
    $instance = $this->createRegistrableInstance();
    $builder = \Drupal::entityTypeManager()->getListBuilder('eventinstance');

    $first = (string) ($builder->buildRow($instance)['date'] ?? '');
    $second = (string) ($builder->buildRow($instance)['date'] ?? '');

    $this->assertNotEmpty($first, 'the first row rendered');
    $this->assertSame($first, $second,
      'the second row is identical, so the first did not mutate the source');
  }

  /**
   * An UNCLONED convert corrupts the entity copy — the bug being guarded.
   *
   * Pinning core's aliasing directly, so that if a future core or contrib
   * change ever makes the computed property hand out copies, this test fails
   * and the clones above can be revisited as unnecessary.
   */
  public function testUnclonedConvertCorruptsTheEntityCopy(): void {
    $instance = $this->createRegistrableInstance();

    $instance->get('date')->start_date
      ->setTimezone(new \DateTimeZone('America/Los_Angeles'));

    $this->assertSame(
      'America/Los_Angeles',
      $instance->get('date')->start_date->getTimezone()->getName(),
      'core still aliases, so our call sites must keep cloning'
    );
  }

  /**
   * Pins the aliasing itself, so a future refactor cannot silently reintroduce
   * in-place mutation without this test noticing.
   */
  public function testComputedDateIsReturnedByReferenceAndMustBeCloned(): void {
    $instance = $this->createRegistrableInstance();

    $this->assertSame(
      $instance->get('date')->start_date,
      $instance->get('date')->start_date,
      'the computed property hands out one shared object, so readers must clone'
    );
  }

}
