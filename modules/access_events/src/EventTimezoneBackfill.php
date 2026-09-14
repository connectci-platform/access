<?php

declare(strict_types=1);

namespace Drupal\access_events;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;

/**
 * Backfills each event series' timezone from its author's account zone.
 *
 * Writes each series' timezone from its author's account zone.
 * That is the contract organizers were always given, and it holds well on real
 * data — 855 of 872 series whose author has a zone set have their first
 * instance land on a round local time in that zone. It is still a default
 * rather than a proof, so provenance is recorded and the rows it cannot settle
 * are reported rather than guessed.
 *
 * There is deliberately no inheritance repair here. field_inheritance 3.x
 * resolves an inherited value from a `field_inheritance` base field, and
 * FieldInheritancePluginBase::getSourceEntity() falls back from a per-field
 * entry to entities[<source entity type>:<bundle>], which covers every
 * inheritance from that source — including fields whose config arrives after
 * the 3.x migration. recurring_events_update_103000() writes that key for
 * every existing instance, so the two fields configured here resolve without
 * any per-field entry. Verified on the full production dataset: a series with
 * 54 instances resolved on all 54 with zero per-field entries present.
 */
class EventTimezoneBackfill {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly Connection $database,
  ) {}

  /**
   * Writes a timezone to every series that has none.
   *
   * Writes the field column directly rather than saving the entity: an entity
   * save would run the recur-config change detection, and on a series with
   * registrations that path throws rather than rebuilding. A backfill must not
   * be able to destroy registrations.
   *
   * @return array
   *   Counts keyed 'author_zone', 'site_default', 'skipped', plus a 'review'
   *   list of series needing a human decision.
   */
  public function backfill(): array {
    $siteDefault = (string) ($this->configFactory->get('system.date')->get('timezone.default') ?: date_default_timezone_get());
    $storage = $this->entityTypeManager->getStorage('eventseries');

    $result = [
      'author_zone' => 0,
      'site_default' => 0,
      'skipped' => 0,
      'review' => [],
    ];

    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach (array_chunk($ids, 50) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $series) {
        if (!$series->hasField('field_event_timezone')) {
          continue;
        }
        $existing = trim((string) ($series->get('field_event_timezone')->value ?? ''));
        if ($existing !== '') {
          // Never overwrite: a re-run must not revert a human correction.
          $result['skipped']++;
          continue;
        }

        $owner = $series->getOwner();
        $authorZone = $owner ? trim((string) ($owner->getTimeZone() ?? '')) : '';
        $derived = $authorZone !== '' ? $authorZone : $siteDefault;

        $this->writeZone($series, $derived, $authorZone !== '' ? 'author' : 'site_default');
        $authorZone !== '' ? $result['author_zone']++ : $result['site_default']++;

        if ($reason = $this->needsReview($series, $derived)) {
          $result['review'][] = [
            'id' => (int) $series->id(),
            'title' => (string) $series->label(),
            'written' => $derived,
            'reason' => $reason,
          ];
        }
      }
      $storage->resetCache($chunk);
    }

    return $result;
  }

  /**
   * Why a series' derived zone should be checked by a human, if it should.
   *
   * Deliberately conservative: these are reported, not corrected. The location
   * cases are the ones production actually shows — a timezone typed into the
   * location field, or a broadcast to satellite sites where a single venue
   * zone is the wrong shape and the real times live in the body prose.
   */
  private function needsReview($series, string $written): ?string {
    $location = $series->hasField('field_location')
      ? trim((string) ($series->get('field_location')->value ?? ''))
      : '';

    if ($location === '') {
      return NULL;
    }
    // A satellite broadcast happens at several clocks at once.
    if (preg_match('/^(multiple|tbd|tba|n\/a|na|varies|various)$/i', $location)) {
      return 'location is "' . $location . '" — may be a broadcast to satellite sites; check the body for the stated time';
    }
    // The workaround this field replaces: a zone written into free text. Only
    // report it when the typed zone DISAGREES with what was written — an
    // author in America/Chicago who typed "CST" is confirming the derived
    // value, not contradicting it, and reporting those buries the real
    // conflicts in noise. Matching is case-sensitive and word-bounded because
    // a case-insensitive match finds "CEST" inside "Commons".
    static $abbreviations = [
      'EST' => 'America/New_York',
      'EDT' => 'America/New_York',
      'CST' => 'America/Chicago',
      'CDT' => 'America/Chicago',
      'MST' => 'America/Denver',
      'MDT' => 'America/Denver',
      'PST' => 'America/Los_Angeles',
      'PDT' => 'America/Los_Angeles',
      'BST' => 'Europe/London',
      'CEST' => 'Europe/Rome',
      'CET' => 'Europe/Rome',
    ];
    if (preg_match('/\b(E[SD]T|C[SD]T|M[SD]T|P[SD]T|BST|CES?T|UTC|GMT)\b/', $location, $m)) {
      $typed = strtoupper($m[1]);
      $implied = $abbreviations[$typed] ?? NULL;
      if ($implied !== NULL && $implied !== $written) {
        return 'location says "' . $typed . '" (' . $implied . ') but the author zone gives ' . $written;
      }
      if ($implied === NULL) {
        return 'location says "' . $typed . '", which has no single zone — check it';
      }
    }
    return NULL;
  }

  /**
   * Writes the zone column and its provenance, without an entity save.
   *
   * Writes the revision table as well as the base table, and for EVERY
   * revision rather than just the current one. A field row missing from a
   * revision reads as empty when that revision is loaded — so reverting a
   * series would see no timezone, resolve generation against the ambient zone
   * instead, and silently reschedule the event. Production has 3,859 series
   * revisions, up to 22 on a single series.
   */
  private function writeZone($series, string $zone, string $provenance): void {
    $base = 'eventseries__field_event_timezone';
    $revision = 'eventseries_revision__field_event_timezone';
    $column = 'field_event_timezone_value';

    if ($this->database->schema()->tableExists($base)) {
      $this->database->merge($base)
        ->keys([
          'entity_id' => $series->id(),
          'deleted' => 0,
          'delta' => 0,
          'langcode' => $series->language()->getId(),
        ])
        ->fields([
          'bundle' => $series->bundle(),
          'revision_id' => $series->getRevisionId(),
          $column => $zone,
        ])
        ->execute();
    }

    if ($this->database->schema()->tableExists($revision)) {
      $revisionIds = $this->database->select('eventseries_revision', 'r')
        ->fields('r', ['vid'])
        ->condition('r.id', $series->id())
        ->execute()
        ->fetchCol();
      foreach ($revisionIds as $vid) {
        $this->database->merge($revision)
          ->keys([
            'entity_id' => $series->id(),
            'revision_id' => $vid,
            'deleted' => 0,
            'delta' => 0,
            'langcode' => $series->language()->getId(),
          ])
          ->fields([
            'bundle' => $series->bundle(),
            $column => $zone,
          ])
          ->execute();
      }
    }
    // Provenance lives in state rather than a field: it is operational data
    // about the migration, not part of the event, and "empty" stops
    // distinguishing derived from human-set the moment the backfill runs.
    $key = 'access_events.timezone_provenance';
    $store = $this->keyValue->get($key);
    $store->set((string) $series->id(), $provenance);
  }

}
