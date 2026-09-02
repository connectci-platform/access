<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * The eventseries presave domain guard.
 *
 * The edit form renders a hidden, zero-option domain_access widget for
 * editors without domain permissions, which round-trips an empty value and
 * clears the stored domains on save (recurring_events' "Confirm Date
 * Changes" button bypasses the old form-submit backfill entirely). The
 * entity-layer guard makes every save path safe: restore the original
 * domains on update; fill the active domain on create; otherwise leave
 * empty. These tests pin the entity-layer contract — widget extraction and
 * the confirm button are form-layer machinery out of kernel reach.
 *
 * @group access_events
 */
class DomainGuardTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // makeCoordinatorSeries() saves an affinity_group node whose presave
    // hook reads field_affinity_group; the base does not seed it (same
    // pattern as the CRUD suites' setUp).
    if (!FieldStorageConfig::loadByName('node', 'field_affinity_group')) {
      FieldStorageConfig::create([
        'field_name' => 'field_affinity_group',
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'cardinality' => 1,
        'settings' => ['target_type' => 'taxonomy_term'],
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_affinity_group',
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
      ])->save();
    }
  }

  /**
   * Registers a stub domain negotiator returning a fixed active domain.
   */
  private function stubActiveDomain(string $id): void {
    $domain = new class($id) {

      public function __construct(private string $id) {}

      public function id(): string {
        return $this->id;
      }

    };
    $negotiator = new class($domain) {

      public function __construct(private object $domain) {}

      public function getActiveDomain(): object {
        return $this->domain;
      }

    };
    \Drupal::getContainer()->set('domain.negotiator', $negotiator);
  }

  /**
   * Reloads a series bypassing static caches, typed for the field API.
   */
  private function reloadSeries(int $id): EventSeries {
    $series = \Drupal::entityTypeManager()->getStorage('eventseries')->loadUnchanged($id);
    assert($series instanceof EventSeries);
    return $series;
  }

  /**
   * An update that arrives with empty domain_access restores the original.
   */
  public function testUpdateWithEmptyDomainRestoresOriginal(): void {
    $series = $this->makeCoordinatorSeries($this->createUser());
    $series->set('domain_access', ['amp_cyberinfrastructure_org'])->save();

    $series->set('domain_access', []);
    $series->set('title', 'Edited');
    $series->save();

    $reloaded = $this->reloadSeries((int) $series->id());
    $this->assertSame(
      ['amp_cyberinfrastructure_org'],
      array_column($reloaded->get('domain_access')->getValue(), 'value')
    );
  }

  /**
   * Restore beats fill: an active domain does not replace stored domains.
   */
  public function testRestoreBeatsActiveDomainOnUpdate(): void {
    $this->stubActiveDomain('nectd8_wpi_edu');
    $series = $this->makeCoordinatorSeries($this->createUser());
    $series->set('domain_access', ['amp_cyberinfrastructure_org'])->save();

    $series->set('domain_access', []);
    $series->save();

    $reloaded = $this->reloadSeries((int) $series->id());
    $this->assertSame(
      ['amp_cyberinfrastructure_org'],
      array_column($reloaded->get('domain_access')->getValue(), 'value')
    );
  }

  /**
   * A create with empty domain_access fills from the active domain.
   *
   * Runs through the REAL hook: the guard must precede the reschedule
   * backstop's isNew() early-return or this never fires.
   */
  public function testCreateWithEmptyDomainFillsActiveDomain(): void {
    $this->stubActiveDomain('amp_cyberinfrastructure_org');

    $series = EventSeries::create([
      'title' => 'Fresh series',
      'type' => 'default',
      'recur_type' => 'custom',
    ]);
    $series->save();

    $this->assertSame(
      ['amp_cyberinfrastructure_org'],
      array_column($series->get('domain_access')->getValue(), 'value')
    );
  }

  /**
   * Explicit domains pass through untouched on create and update.
   */
  public function testExplicitDomainsUntouched(): void {
    $this->stubActiveDomain('nectd8_wpi_edu');

    $series = EventSeries::create([
      'title' => 'Scoped series',
      'type' => 'default',
      'recur_type' => 'custom',
      'domain_access' => ['ccmnet_org'],
    ]);
    $series->save();
    $this->assertSame(
      ['ccmnet_org'],
      array_column($series->get('domain_access')->getValue(), 'value')
    );

    $series->set('domain_access', ['amp_cyberinfrastructure_org']);
    $series->save();
    $reloaded = $this->reloadSeries((int) $series->id());
    $this->assertSame(
      ['amp_cyberinfrastructure_org'],
      array_column($reloaded->get('domain_access')->getValue(), 'value')
    );
  }

  /**
   * Without a negotiator service, an empty create stays empty, no crash.
   *
   * Also pins the never-domained-update rule: an update whose ORIGINAL was
   * empty is left empty (all-affiliates series are not silently narrowed),
   * even when an active domain exists.
   */
  public function testNoNegotiatorAndNeverDomainedStayEmpty(): void {
    $series = EventSeries::create([
      'title' => 'Domainless series',
      'type' => 'default',
      'recur_type' => 'custom',
    ]);
    $series->save();
    $this->assertTrue($series->get('domain_access')->isEmpty());

    $this->stubActiveDomain('amp_cyberinfrastructure_org');
    $series->set('title', 'Edited domainless');
    $series->save();
    $reloaded = $this->reloadSeries((int) $series->id());
    $this->assertTrue($reloaded->get('domain_access')->isEmpty());
  }

  /**
   * The deploy hook restores cleared series from revisions AND saves their
   * instances so they inherit (the ACCESS view reads the instance index — a
   * series-only restore never reaches it).
   */
  public function testDeployHookRestoresSeriesAndInstances(): void {
    $series = $this->makeCoordinatorSeries($this->createUser());
    $series->set('domain_access', ['amp_cyberinfrastructure_org']);
    $series->setNewRevision(TRUE);
    $series->save();
    // A second, newer revision (domain carried forward). The simulated clear
    // below empties THIS revision's rows; the revision walk must then find
    // the older domained revision — deleting the only domained revision
    // would correctly classify the series as never-domained and the test
    // would fail for the wrong reason.
    $series->setNewRevision(TRUE);
    $series->save();

    // Simulate the historical clearing: bypass the guard by writing the
    // field tables directly (the guard would now prevent an empty save).
    $database = \Drupal::database();
    foreach (['eventseries__domain_access', 'eventseries_revision__domain_access'] as $table) {
      $database->delete($table)
        ->condition('entity_id', $series->id())
        ->condition('revision_id', $series->getRevisionId())
        ->execute();
    }
    // Clear the instance's inherited value the same way.
    $instance = current(\Drupal::entityTypeManager()->getStorage('eventinstance')
      ->loadByProperties(['eventseries_id' => $series->id()]));
    assert($instance instanceof EventInstance);
    $database->delete('eventinstance__domain_access')
      ->condition('entity_id', $instance->id())
      ->execute();
    \Drupal::entityTypeManager()->getStorage('eventseries')->resetCache();
    \Drupal::entityTypeManager()->getStorage('eventinstance')->resetCache();

    $cleared = $this->reloadSeries((int) $series->id());
    $this->assertTrue($cleared->get('domain_access')->isEmpty(), 'Setup: series is cleared.');

    require_once __DIR__ . '/../../../access_events.deploy.php';
    $sandbox = [];
    do {
      $result = access_events_deploy_restore_series_domains($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);

    $restoredSeries = $this->reloadSeries((int) $series->id());
    $this->assertSame(
      ['amp_cyberinfrastructure_org'],
      array_column($restoredSeries->get('domain_access')->getValue(), 'value')
    );
    $restoredInstance = $this->reloadInstance($instance);
    $this->assertSame(
      ['amp_cyberinfrastructure_org'],
      array_column($restoredInstance->get('domain_access')->getValue(), 'value'),
      'Instance re-inherited the restored domain.'
    );
    $this->assertStringContainsString('restored', strtolower((string) $result));
  }


  /**
   * Creates a multi-value string field on eventseries for strip-guard tests.
   */
  private function createSeriesOptionField(string $fieldName): void {
    if (!FieldStorageConfig::loadByName('eventseries', $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'eventseries',
        'type' => 'string',
        'cardinality' => -1,
      ])->save();
      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'eventseries',
        'bundle' => 'default',
      ])->save();
    }
  }

  /**
   * A non-privileged editor's save cannot strip restricted affiliations.
   *
   * The form hides the three restricted affiliation options from editors
   * without news_pm/administrator, so their submissions round-trip WITHOUT
   * any stored restricted value; the strip guard restores what they could
   * not have seen (they cannot have deselected an invisible checkbox).
   */
  public function testStripGuardRestoresHiddenAffiliationValues(): void {
    $this->createSeriesOptionField('field_affiliation');
    $editor = $this->createUser();
    $this->setCurrentUser($editor);

    $series = $this->makeCoordinatorSeries($editor);
    $series->set('field_affiliation', [
      ['value' => 'access_nairr_office_hours'],
      ['value' => 'ACCESS Collaboration'],
    ]);
    $series->save();

    // Simulate the lossy round-trip: the submitted set lacks the hidden value.
    $series->set('field_affiliation', [['value' => 'ACCESS Collaboration']]);
    $series->save();

    $reloaded = $this->reloadSeries((int) $series->id());
    $values = array_column($reloaded->get('field_affiliation')->getValue(), 'value');
    sort($values);
    $this->assertSame(['ACCESS Collaboration', 'access_nairr_office_hours'], $values);
  }

  /**
   * A news_pm removing a restricted affiliation is honored — they saw it.
   */
  public function testStripGuardHonorsPrivilegedRemoval(): void {
    $this->createSeriesOptionField('field_affiliation');
    $editor = $this->createUser([], NULL, FALSE, ['roles' => ['news_pm']]);
    $this->setCurrentUser($editor);

    $series = $this->makeCoordinatorSeries($editor);
    $series->set('field_affiliation', [
      ['value' => 'access_nairr_office_hours'],
      ['value' => 'ACCESS Collaboration'],
    ]);
    $series->save();

    $series->set('field_affiliation', [['value' => 'ACCESS Collaboration']]);
    $series->save();

    $reloaded = $this->reloadSeries((int) $series->id());
    $this->assertSame(
      ['ACCESS Collaboration'],
      array_column($reloaded->get('field_affiliation')->getValue(), 'value')
    );
  }

  /**
   * The digest option is preserved when edited from a non-amp domain.
   *
   * The form hides the bi-weekly-digest share option on every domain except
   * ACCESS Support, so an off-amp edit round-trips without it; the guard
   * restores it. On the amp domain the option is visible, so a removal there
   * sticks.
   */
  public function testStripGuardDigestValueByDomainContext(): void {
    $this->createSeriesOptionField('field_choose_where_to_share_this');
    $editor = $this->createUser();
    $this->setCurrentUser($editor);
    $series = $this->makeCoordinatorSeries($editor);
    $series->set('field_choose_where_to_share_this', [
      ['value' => 'on_the_announcements_page'],
      ['value' => 'in_the_access_support_bi_weekly_digest'],
    ]);
    $series->save();

    // Off-amp context (option hidden): removal is restored.
    $this->stubActiveDomain('ccmnet_org');
    $series->set('field_choose_where_to_share_this', [['value' => 'on_the_announcements_page']]);
    $series->save();
    $values = array_column($this->reloadSeries((int) $series->id())->get('field_choose_where_to_share_this')->getValue(), 'value');
    sort($values);
    $this->assertSame(['in_the_access_support_bi_weekly_digest', 'on_the_announcements_page'], $values);

    // Amp context (option visible): removal sticks.
    $this->stubActiveDomain('amp_cyberinfrastructure_org');
    $series = $this->reloadSeries((int) $series->id());
    $series->set('field_choose_where_to_share_this', [['value' => 'on_the_announcements_page']]);
    $series->save();
    $this->assertSame(
      ['on_the_announcements_page'],
      array_column($this->reloadSeries((int) $series->id())->get('field_choose_where_to_share_this')->getValue(), 'value')
    );
  }


}
