<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Covers _access_events_build_allocation_grant_widget().
 *
 * @group access_events
 *
 * D8-2823: the field_event_allocation_grant widget replaces its options with
 * the EDITING user's own grant list (from the allocations API), with
 * "No Allocation" (0) first. A stored grant that isn't in that list —
 * another user's grant, or the owner's own grant after it rotates out of the
 * API — used to render with no matching option, so the browser preselected
 * "No Allocation" and a normal save silently wrote 0. A grantless editor's
 * widget was additionally forced to default value 0 before being hidden,
 * defeating Drupal's hidden-element round-trip safety and guaranteeing that
 * save wiped the stored grant.
 *
 * The fix (widget layer, not presave — a presave guard cannot distinguish a
 * deliberate "No Allocation" choice from a browser default): when the
 * stored value is absent from the editor's option list, append it as an
 * explicit option labeled with the raw grant plus "(current value)" and
 * select it by default; and never force the no-grants branch's default to 0.
 *
 * Exercises the widget-building helper directly (extracted from
 * access_events_form_alter() specifically so it's testable without needing
 * to build the full eventseries add/edit form, which requires the entire
 * recur/registration field set to be wired up).
 */
class AllocationGrantWidgetTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!FieldStorageConfig::loadByName('eventseries', 'field_event_allocation_grant')) {
      FieldStorageConfig::create([
        'field_name' => 'field_event_allocation_grant',
        'entity_type' => 'eventseries',
        'type' => 'string',
        'cardinality' => 1,
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_event_allocation_grant',
        'entity_type' => 'eventseries',
        'bundle' => 'default',
      ])->save();
    }
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Swaps the allocations API service for a fake returning a fixed grant list.
   *
   * The widget builder only ever calls ->getGrantList($user) on this
   * service, so the fake need not extend or otherwise resemble the real
   * XsedeApi (whose constructor pulls in key/http_client/logger/messenger
   * dependencies unrelated to what is under test here).
   */
  private function fakeGrantsApi(array $grantList): void {
    $fake = new class($grantList) {

      public function __construct(private array $grantList) {
      }

      /**
       * Returns the fixed fake grant list, ignoring $user.
       */
      public function getGrantList($user) {
        return $this->grantList;
      }

    };
    $this->container->set('access_events.XsedeApi', $fake);
  }

  /**
   * Runs the widget builder against a minimal widget array.
   *
   * Mirrors the shape access_events_form_alter() hands it.
   */
  private function buildWidget(EventSeries $series, string $username = 'someuser'): array {
    $form = [
      'field_event_allocation_grant' => ['widget' => [0 => ['value' => []]]],
    ];
    _access_events_build_allocation_grant_widget($form, $series, $username);
    return $form['field_event_allocation_grant']['widget'][0]['value'];
  }

  /**
   * A stored grant absent from the editor's own list is surfaced, not dropped.
   *
   * Covers both real-world triggers from the ticket: another user's grant,
   * and the owner's own grant after it rotates out of the allocations API —
   * both look identical to the widget builder (a stored value with no
   * matching option).
   */
  public function testForeignGrantSurfacedAsCurrentValueOption(): void {
    $this->fakeGrantsApi(['TRA111111' => 'Some Other Grant']);

    $series = EventSeries::create([
      'title' => 'Recurring Training Workshop',
      'recur_type' => 'custom',
      'type' => 'default',
      'field_event_allocation_grant' => 'TRA999999',
    ]);
    $series->save();

    $widget = $this->buildWidget($series);

    $this->assertSame('No Allocation', $widget['#options'][0]);
    $this->assertArrayHasKey('TRA999999', $widget['#options']);
    $this->assertStringContainsString('(current value)', (string) $widget['#options']['TRA999999']);
    $this->assertSame('TRA999999', $widget['#default_value']);
    // Grants exist for this editor, so the widget must stay visible/editable
    // — the editor can still deliberately change the value.
    $this->assertArrayNotHasKey('#access', $widget);
  }

  /**
   * A stored grant already in the editor's list is left alone, no duplicate.
   */
  public function testOwnGrantStillInListIsUnaffected(): void {
    $this->fakeGrantsApi(['TRA111111' => 'Owned Grant']);

    $series = EventSeries::create([
      'title' => 'Current Workshop',
      'recur_type' => 'custom',
      'type' => 'default',
      'field_event_allocation_grant' => 'TRA111111',
    ]);
    $series->save();

    $widget = $this->buildWidget($series);

    $this->assertSame('Owned Grant', $widget['#options']['TRA111111']);
    $this->assertStringNotContainsString('(current value)', $widget['#options']['TRA111111']);
    $this->assertArrayNotHasKey('#default_value', $widget);
  }

  /**
   * A grantless editor's save must not deterministically wipe the grant.
   *
   * The widget stays hidden (#access FALSE), but nothing may force its
   * default to 0 any more: a hidden element round-trips the entity's real
   * stored default safely once the override is gone, so the stored grant
   * must still show up as an option and as #default_value.
   */
  public function testNoGrantsBranchPreservesStoredValue(): void {
    $this->fakeGrantsApi([]);

    $series = EventSeries::create([
      'title' => 'Owned By Grantless Editor',
      'recur_type' => 'custom',
      'type' => 'default',
      'field_event_allocation_grant' => 'TRA555555',
    ]);
    $series->save();

    $widget = $this->buildWidget($series);

    $this->assertFalse($widget['#access']);
    $this->assertSame('TRA555555', $widget['#default_value']);
    $this->assertArrayHasKey('TRA555555', $widget['#options']);
  }

  /**
   * A new event with no stored grant keeps "No Allocation" as the only default.
   */
  public function testNewEventKeepsNoAllocationFirstWithNoForcedDefault(): void {
    $this->fakeGrantsApi(['TRA111111' => 'Some Grant']);

    $series = EventSeries::create([
      'title' => 'Brand New Event',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);

    $widget = $this->buildWidget($series);

    $this->assertSame([0, 'TRA111111'], array_keys($widget['#options']));
    $this->assertSame('No Allocation', $widget['#options'][0]);
    $this->assertArrayNotHasKey('#default_value', $widget);
  }

}
