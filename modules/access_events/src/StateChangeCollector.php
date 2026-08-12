<?php

declare(strict_types=1);

namespace Drupal\access_events;

/**
 * Carries one entity save's orchestration outcomes to the API response layer.
 *
 * A single entity save can ripple into several side effects within the same
 * request — notifications sent, mirrored occurrences touched, a flag set —
 * and the controller assembling the write-envelope response needs to report
 * exactly what happened without re-deriving it. Orchestration code records
 * those outcomes here as it runs; the controller drains them once, keyed by
 * the entity type and id it acted on.
 *
 * Also carries the series-sweep marker: a single nullable series id that
 * distinguishes a series-wide archive (every occurrence being cancelled as
 * part of one sweep) from an individual occurrence cancel acting alone, so
 * per-occurrence logic can tell which situation it is running in without a
 * parameter threaded through every call.
 *
 * Scoped per request — nothing here persists past the request that created
 * it.
 */
final class StateChangeCollector {

  /**
   * Accumulated integer counters, keyed by "entityType:id" then field name.
   *
   * @var array<string, array<string, int|bool>>
   */
  private array $counters = [];

  /**
   * The series id currently being swept, or NULL when no sweep is active.
   */
  private ?int $sweepingSeriesId = NULL;

  /**
   * Adds $amount to the running count for one (entityType, id, field).
   *
   * @param string $entityType
   *   The entity type id (e.g. 'eventseries', 'eventinstance').
   * @param int $id
   *   The entity id.
   * @param string $field
   *   The outcome field name (e.g. 'notified').
   * @param int $amount
   *   The amount to add. Defaults to 1.
   */
  public function record(string $entityType, int $id, string $field, int $amount = 1): void {
    $key = $this->key($entityType, $id);
    $current = $this->counters[$key][$field] ?? 0;
    $this->counters[$key][$field] = (is_int($current) ? $current : 0) + $amount;
  }

  /**
   * Sets a boolean outcome marker for one (entityType, id, field).
   *
   * @param string $entityType
   *   The entity type id.
   * @param int $id
   *   The entity id.
   * @param string $field
   *   The outcome field name (e.g. 'notifications_disabled').
   */
  public function flag(string $entityType, int $id, string $field): void {
    $key = $this->key($entityType, $id);
    $this->counters[$key][$field] = TRUE;
  }

  /**
   * Returns and clears the accumulated outcomes for one (entityType, id).
   *
   * @param string $entityType
   *   The entity type id.
   * @param int $id
   *   The entity id.
   *
   * @return array<string, int|bool>
   *   The outcome fields recorded for this key, or an empty array if none.
   */
  public function drain(string $entityType, int $id): array {
    $key = $this->key($entityType, $id);
    $outcomes = $this->counters[$key] ?? [];
    unset($this->counters[$key]);
    return $outcomes;
  }

  /**
   * Clears only the exact (entityType, id) key, leaving every other key intact.
   *
   * @param string $entityType
   *   The entity type id.
   * @param int $id
   *   The entity id.
   */
  public function resetOwn(string $entityType, int $id): void {
    unset($this->counters[$this->key($entityType, $id)]);
  }

  /**
   * Marks a series-wide sweep as active for $seriesId.
   *
   * @param int $seriesId
   *   The eventseries id being swept.
   */
  public function beginSweep(int $seriesId): void {
    $this->sweepingSeriesId = $seriesId;
  }

  /**
   * Clears the active sweep marker, if any.
   */
  public function endSweep(): void {
    $this->sweepingSeriesId = NULL;
  }

  /**
   * Whether $seriesId is the series currently being swept.
   *
   * @param int $seriesId
   *   The eventseries id to check.
   *
   * @return bool
   *   TRUE only when a sweep is active AND its series id matches $seriesId.
   */
  public function isSweeping(int $seriesId): bool {
    return $this->sweepingSeriesId === $seriesId;
  }

  /**
   * Builds the internal storage key for one (entityType, id) pair.
   */
  private function key(string $entityType, int $id): string {
    return $entityType . ':' . $id;
  }

}
