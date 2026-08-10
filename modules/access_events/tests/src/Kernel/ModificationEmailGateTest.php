<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events_registration\Entity\Registrant;

/**
 * Tests the reschedule-notice reaction's gate: EventStateReactions::
 * reactToInstanceModified() (via instanceUpdate()) and the
 * access_events_module_implements_alter() unimplement of contrib's
 * gate-less recurring_events_registration_entity_update().
 *
 * A date edit only emails registrants when the saved revision is the
 * default one, the instance is published BOTH before and after the save
 * (a restore that also moves the date fires ONLY the reinstatement reaction's
 * notice, never this one — see testOneSaveRestoreWithDateEditSendsOnly
 * Reinstatement()), and the date field actually changed. Recipients are
 * decided from the UNION of the old and new end-date boundaries, not a
 * DB count taken after the save has already overwritten the row — see
 * testFutureToPastEditStillNotifies().
 *
 * @coversDefaultClass \Drupal\access_events\EventStateReactions
 * @group access_events
 */
class ModificationEmailGateTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableEventNotifications();
  }

  /**
   * A date edit on an ARCHIVED (dark) instance emails nobody — the instance
   * is not published after the save, so gate #2 refuses.
   */
  public function testDarkInstanceDateEditEmailsNobody(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'r1'), $instance);

    $instance->setSyncing(TRUE);
    $instance->set('moderation_state', 'archived');
    $instance->save();

    $instance = $this->reloadInstance($instance);
    $instance->set('date', ['value' => '2999-03-01T10:00:00', 'end_value' => '2999-03-01T12:00:00']);
    $instance->save();

    $this->assertQueueCount('instance_modification_notification', 0);
  }

  /**
   * A date edit on a LIVE (published before and after) instance emails the
   * registrant AND the outcome is recorded on the collector under both the
   * instance's own key and its parent series' key.
   */
  public function testLiveDateEditEmailsRegistrantsAndRecordsDrain(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'r2'), $instance);
    $instanceId = (int) $instance->id();
    $seriesId = (int) $instance->get('eventseries_id')->target_id;

    $instance->set('date', ['value' => '2999-03-01T10:00:00', 'end_value' => '2999-03-01T12:00:00']);
    $instance->save();

    $this->assertQueueCount('instance_modification_notification', 1);

    $collector = \Drupal::service('access_events.state_change_collector');
    $instanceOutcomes = $collector->drain('eventinstance', $instanceId);
    $seriesOutcomes = $collector->drain('eventseries', $seriesId);

    $this->assertSame(1, $instanceOutcomes['notified'] ?? NULL);
    $this->assertSame(1, $seriesOutcomes['notified'] ?? NULL);
  }

  /**
   * A single save that BOTH restores (archived -> published) AND changes the
   * date fires ONLY the reinstatement reaction's notice — never also this
   * modification notice. Gate #3 (the ORIGINAL state must already be
   * published) is what enforces this: instanceUpdate() routes a from!==
   * published transition to reactToInstanceReinstated() instead, so
   * reactToInstanceModified() is never even reached for this save.
   */
  public function testOneSaveRestoreWithDateEditSendsOnlyReinstatement(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'r3'), $instance);

    $instance->setSyncing(TRUE);
    $instance->set('moderation_state', 'archived');
    $instance->save();

    // One save: restore AND move the date.
    $instance = $this->reloadInstance($instance);
    $instance->set('moderation_state', 'published');
    $instance->set('date', ['value' => '2999-04-01T10:00:00', 'end_value' => '2999-04-01T12:00:00']);
    $instance->save();

    $this->assertQueueCount('event_reinstated_notification', 1);
    $this->assertQueueCount('instance_modification_notification', 0);
  }

  /**
   * A registrant who was future under the OLD end date is still notified
   * even when the edit moves the instance's end date into the PAST — the
   * gate reads the UNION of old/new boundaries, not a DB count taken after
   * the save has already overwritten the row with the new (past) date.
   *
   * The save here is a raw entity-level ->save() (not the API layer), so the
   * database genuinely holds the past date at the moment the reaction
   * decides whether to notify — proving a "count against storage post-save"
   * implementation would wrongly see nothing here.
   */
  public function testFutureToPastEditStillNotifies(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'r4'), $instance);

    $instance->set('date', ['value' => '2000-01-01T10:00:00', 'end_value' => '2000-01-01T12:00:00']);
    $instance->save();

    $reloaded = $this->reloadInstance($instance);
    $this->assertStringContainsString('2000-01-01', $reloaded->get('date')->value, 'The DB genuinely holds the past date.');

    $this->assertQueueCount('instance_modification_notification', 1);
  }

  /**
   * A date edit on an instance in a PENDING (non-published, non-archived)
   * state, such as needs_adjustment, emails nobody — gate #2 requires
   * published both before and after the save.
   */
  public function testPendingDraftDateEditEmailsNobody(): void {
    $instance = $this->createRegistrableInstance();
    $this->registerUser($this->createUser([], 'r5'), $instance);

    $instance->setSyncing(TRUE);
    $instance->set('moderation_state', 'needs_adjustment');
    $instance->save();

    $instance = $this->reloadInstance($instance);
    $instance->set('date', ['value' => '2999-05-01T10:00:00', 'end_value' => '2999-05-01T12:00:00']);
    $instance->save();

    $this->assertQueueCount('instance_modification_notification', 0);
  }

  /**
   * recurring_events_registration's own hook_entity_update() (the gate-less
   * original this replaces) is unimplemented for the entity_update hook —
   * access_events_eventinstance_update() is the only implementation left
   * that can fire the reschedule notice.
   */
  public function testContribModificationHookIsUnimplemented(): void {
    // ModuleHandlerInterface has no public "list implementations" accessor —
    // hasImplementations() would give a false positive here, since the
    // recurring_events_registration_entity_update() FUNCTION still exists
    // (module_implements_alter() removes it from the discovered
    // implementations list, it does not delete the function). invokeAllWith()
    // is the public API that actually walks that discovered list, so record
    // which modules it visits for entity_update.
    $invokedModules = [];
    \Drupal::moduleHandler()->invokeAllWith('entity_update', function ($hook, string $module) use (&$invokedModules) {
      $invokedModules[] = $module;
    });
    $this->assertNotContains(
      'recurring_events_registration',
      $invokedModules,
      'recurring_events_registration must be unimplemented for entity_update — see access_events_module_implements_alter().',
    );
  }

  /**
   * The registration-requires-published-occurrence gate: a bare, programmatic Registrant::create()->save() against a DRAFT
   * (never-published) instance throws — this is the presave gate, not the
   * form-validate mirror, so it must hold even outside the registrant form.
   *
   * This case belongs here rather than in RegistrationApiTest because that
   * class's $modules list deliberately omits access_events (it tests
   * RegistrationApiController in isolation from the access_events hooks), so
   * access_events_entity_presave() never runs there.
   */
  public function testRegistrantPresaveGateBlocksDraftInstanceEvenProgrammatically(): void {
    $series = EventSeries::create([
      'title' => 'Draft Event',
      'recur_type' => 'custom',
      'type' => 'default',
    ]);
    $series->save();
    $instance = EventInstance::create([
      'eventseries_id' => $series->id(),
      'type' => 'default',
      'date' => ['value' => '2999-06-01T10:00:00', 'end_value' => '2999-06-01T12:00:00'],
    ]);
    // Deliberately do NOT set moderation_state — the workflow's initial
    // state (draft) is what a never-published instance actually starts as.
    $instance->save();
    $user = $this->createUser([], 'r6');

    // Core's SqlContentEntityStorage wraps a presave-hook exception in an
    // EntityStorageException, with the original RuntimeException as its
    // previous exception — same pattern InstanceReactionsTest uses for the
    // occurrence-publish-under-unpublished-event refusal's own bare-save throw.
    $threw = FALSE;
    try {
      Registrant::create([
        'user_id' => $user->id(),
        'eventinstance_id' => $instance->id(),
        'eventseries_id' => $series->id(),
        'email' => 'r6@example.com',
        'waitlist' => 0,
        'type' => 'default',
      ])->save();
    }
    catch (\Drupal\Core\Entity\EntityStorageException $e) {
      $threw = TRUE;
      $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
      $this->assertSame('Registration is closed — this occurrence is not currently published.', $e->getPrevious()->getMessage());
    }
    $this->assertTrue($threw, 'A new registrant against a draft instance must be refused.');
  }

  /**
   * The registration-requires-published-occurrence gate's exemption: an UPDATE save on an existing registrant (e.g. a waitlist
   * promotion) is never gated, even if the instance is not published at the
   * time of the update — only NEW registrant saves are gated.
   */
  public function testWaitlistPromotionUpdateExempt(): void {
    $instance = $this->createRegistrableInstance();
    $registrant = $this->registerUser($this->createUser([], 'r7'), $instance, waitlist: TRUE);

    // Contrive the instance into a non-published state after the registrant
    // already exists — an update save on the registrant must not re-run the
    // new-registrant publish gate against this now-dark instance.
    $instance->setSyncing(TRUE);
    $instance->set('moderation_state', 'archived');
    $instance->save();

    $registrant->set('waitlist', 0);
    $registrant->save();

    $reloaded = \Drupal::entityTypeManager()->getStorage('registrant')->loadUnchanged($registrant->id());
    $this->assertSame('0', (string) $reloaded->get('waitlist')->value, 'The update proceeded without being gated.');
  }

}
