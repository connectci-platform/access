<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\access_affinitygroup\Service\ProfileService;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\access_affinitygroup\Traits\AssistantConsentFieldsTrait;
use Drupal\user\Entity\User;

/**
 * Tests the assistant consent fields, their update hook, and presave stamping.
 *
 * @group access_affinitygroup
 */
class ProfileConsentFieldsTest extends KernelTestBase {

  use AssistantConsentFieldsTrait;

  /**
   * Modules to enable.
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'key',
    'access',
    'access_affinitygroup',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * Creates the user form display a deployed site already has.
   */
  private function createUserFormDisplay(): EntityFormDisplay {
    $display = EntityFormDisplay::create([
      'targetEntityType' => 'user',
      'bundle' => 'user',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->save();
    return $display;
  }

  /**
   * The update hook creates all three fields on a site that lacks them.
   */
  public function testUpdateHookCreatesFieldsOnInstalledSite(): void {
    $this->createUserFormDisplay();
    $this->assertArrayNotHasKey(ProfileService::CONSENT_FIELD, $this->container->get('entity_field.manager')->getFieldStorageDefinitions('user'));

    $this->installConsentField();

    $consent = FieldStorageConfig::loadByName('user', ProfileService::CONSENT_FIELD);
    $this->assertNotNull($consent);
    $this->assertSame('list_string', $consent->getType());
    $this->assertSame(['allow' => 'Allow', 'deny' => 'Deny'], $consent->getSetting('allowed_values'));
    $this->assertSame('integer', FieldStorageConfig::loadByName('user', ProfileService::CONSENT_VERSION_FIELD)->getType());
    $this->assertSame('timestamp', FieldStorageConfig::loadByName('user', ProfileService::CONSENT_ASKED_FIELD)->getType());

    $field = FieldConfig::loadByName('user', 'user', ProfileService::CONSENT_FIELD);
    $this->assertSame('Let AI assistants personalize answers using my profile', $field->getLabel());
    $this->assertStringContainsString('your profile and account information', $field->getDescription());
    $this->assertNotNull(FieldConfig::loadByName('user', 'user', ProfileService::CONSENT_VERSION_FIELD));
    $this->assertNotNull(FieldConfig::loadByName('user', 'user', ProfileService::CONSENT_ASKED_FIELD));

    // The portal config's UUIDs, so the config import after updb keeps the
    // fields instead of recreating them.
    $this->assertSame('67e8dfc4-fa0e-418f-8f39-7bd48f29f025', $consent->uuid());
    $this->assertSame('2b502270-b2af-462f-abe0-d5abfc78f6ea', $field->uuid());
    $this->assertSame('f5d0c1fe-6d07-4aca-ae84-22ec03915e87', FieldStorageConfig::loadByName('user', ProfileService::CONSENT_VERSION_FIELD)->uuid());
    $this->assertSame('46647a6c-afe0-4da2-a12d-b1e7d181eb43', FieldConfig::loadByName('user', 'user', ProfileService::CONSENT_VERSION_FIELD)->uuid());
    $this->assertSame('f69b05cf-9674-4369-a332-592a4792f2e3', FieldStorageConfig::loadByName('user', ProfileService::CONSENT_ASKED_FIELD)->uuid());
    $this->assertSame('b73a6eb5-e542-4878-8061-4af576385109', FieldConfig::loadByName('user', 'user', ProfileService::CONSENT_ASKED_FIELD)->uuid());

    $display = EntityFormDisplay::load('user.user.default');
    $this->assertSame('options_buttons', $display->getComponent(ProfileService::CONSENT_FIELD)['type']);
    $this->assertNull($display->getComponent(ProfileService::CONSENT_VERSION_FIELD));
    $this->assertNull($display->getComponent(ProfileService::CONSENT_ASKED_FIELD));
  }

  /**
   * Re-running the update hook is safe and relabels an older consent field.
   */
  public function testUpdateHookIsRerunnableAndRelabels(): void {
    $this->createUserFormDisplay();
    $this->installConsentField();
    $field = FieldConfig::loadByName('user', 'user', ProfileService::CONSENT_FIELD);
    $field->set('label', 'Old label')->set('description', 'Old description')->save();

    $this->installConsentField();

    $field = FieldConfig::loadByName('user', 'user', ProfileService::CONSENT_FIELD);
    $this->assertSame('Let AI assistants personalize answers using my profile', $field->getLabel());
    $this->assertStringStartsWith('When on, AI assistants on this site may use your profile and account information to tailor their answers, such as your name, institution, academic status, and the resources you have access to with your login on each. You can turn this on or off at any time.', $field->getDescription());
  }

  /**
   * A new user's consent is empty, which reads as unset with no asked time.
   */
  public function testNewUserIsUnset(): void {
    $this->installConsentField();
    $user = User::create(['name' => 'fresh']);
    $user->save();
    $svc = $this->container->get('access_affinitygroup.profile');
    $this->assertSame('unset', $svc->consentState($user));
    $this->assertNull($svc->askedAt($user));
    $this->assertTrue($user->get(ProfileService::CONSENT_VERSION_FIELD)->isEmpty());
  }

  /**
   * A plain entity save that changes consent stamps the version and time.
   */
  public function testPresaveStampsVersionAndAskedOnChange(): void {
    $this->installConsentField();
    $user = User::create(['name' => 'stamp']);
    $user->save();

    $before = \Drupal::time()->getCurrentTime();
    $user->set(ProfileService::CONSENT_FIELD, 'allow');
    $user->save();

    $user = User::load($user->id());
    $this->assertSame(ProfileService::CONSENT_VERSION, (int) $user->get(ProfileService::CONSENT_VERSION_FIELD)->value);
    $this->assertGreaterThanOrEqual($before, (int) $user->get(ProfileService::CONSENT_ASKED_FIELD)->value);
    $this->assertSame('allow', $this->container->get('access_affinitygroup.profile')->consentState($user));
  }

  /**
   * A save that leaves consent alone does not touch the stamps.
   */
  public function testPresaveLeavesStampsWhenConsentUnchanged(): void {
    $this->installConsentField();
    $user = User::create(['name' => 'same']);
    $user->set(ProfileService::CONSENT_FIELD, 'deny');
    $user->save();
    $user->set(ProfileService::CONSENT_ASKED_FIELD, 1000);
    $user->save();

    $user->set('timezone', 'America/Chicago');
    $user->save();

    $user = User::load($user->id());
    $this->assertSame(1000, (int) $user->get(ProfileService::CONSENT_ASKED_FIELD)->value);
    $this->assertSame('deny', $this->container->get('access_affinitygroup.profile')->consentState($user));
  }

  /**
   * A stored choice from an older consent version reads as unset.
   */
  public function testOlderVersionChoiceReadsAsUnset(): void {
    $this->installConsentField();
    $user = User::create(['name' => 'old']);
    $user->set(ProfileService::CONSENT_FIELD, 'allow');
    $user->save();
    $user->set(ProfileService::CONSENT_VERSION_FIELD, ProfileService::CONSENT_VERSION - 1);
    $user->save();

    $svc = $this->container->get('access_affinitygroup.profile');
    $this->assertSame('unset', $svc->consentState($user));
    $this->assertNull($svc->askedAt($user));
  }

  /**
   * A stored choice with no version at all also reads as unset.
   */
  public function testChoiceWithoutVersionReadsAsUnset(): void {
    $this->installConsentField();
    $user = User::create(['name' => 'noversion']);
    $user->set(ProfileService::CONSENT_FIELD, 'deny');
    $user->save();
    $user->set(ProfileService::CONSENT_VERSION_FIELD, NULL);
    $user->save();

    $this->assertSame('unset', $this->container->get('access_affinitygroup.profile')->consentState($user));
  }

  /**
   * The asked time is reported as ISO 8601 UTC.
   */
  public function testAskedAtIsIso8601Utc(): void {
    $this->installConsentField();
    $user = User::create(['name' => 'iso']);
    $user->set(ProfileService::CONSENT_FIELD, 'allow');
    $user->save();
    $user->set(ProfileService::CONSENT_ASKED_FIELD, 1767225600);
    $user->save();

    $this->assertSame('2026-01-01T00:00:00Z', $this->container->get('access_affinitygroup.profile')->askedAt($user));
  }

  /**
   * Choosing on the form over an outdated choice counts, even if it matches.
   */
  public function testFormChoiceStampsAnOutdatedMatchingChoice(): void {
    $this->installConsentField();
    $user = User::create(['name' => 'reaffirm']);
    $user->set(ProfileService::CONSENT_FIELD, 'deny');
    $user->save();
    $user->set(ProfileService::CONSENT_VERSION_FIELD, ProfileService::CONSENT_VERSION - 1);
    $user->save();
    $svc = $this->container->get('access_affinitygroup.profile');
    $this->assertTrue($svc->isStale($user));

    // A plain save with the same value doesn't bring the old choice back.
    $user->save();
    $this->assertSame('unset', $svc->consentState($user));

    // The form's entity builder does, because the user just chose it.
    $svc->stampFormChoice($user);
    $user->save();
    $this->assertSame('deny', $svc->consentState($user));
    $this->assertFalse($svc->isStale($user));
  }

  /**
   * An empty choice on the form isn't stamped by the form builder.
   */
  public function testFormChoiceIgnoresAnEmptyChoice(): void {
    $this->installConsentField();
    $user = User::create(['name' => 'empty']);
    $user->save();
    $svc = $this->container->get('access_affinitygroup.profile');
    $svc->stampFormChoice($user);
    $this->assertTrue($user->get(ProfileService::CONSENT_VERSION_FIELD)->isEmpty());
  }

  /**
   * Resetting for a version bump clears every answer without stamping.
   */
  public function testResetAllConsentClearsEveryAnswer(): void {
    $this->installConsentField();
    $allow = User::create(['name' => 'a']);
    $allow->set(ProfileService::CONSENT_FIELD, 'allow');
    $allow->save();
    $deny = User::create(['name' => 'd']);
    $deny->set(ProfileService::CONSENT_FIELD, 'deny');
    $deny->save();
    $never = User::create(['name' => 'n']);
    $never->save();

    $svc = $this->container->get('access_affinitygroup.profile');
    $changed = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged($deny->id())->getChangedTime();
    $this->assertSame(2, $svc->resetAllConsent($this->container->get('entity_type.manager'), $this->container->get('database')));

    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    foreach ([$allow, $deny] as $user) {
      $user = $storage->loadUnchanged($user->id());
      $this->assertSame('unset', $svc->consentState($user));
      $this->assertNull($svc->askedAt($user));
      $this->assertTrue($user->get(ProfileService::CONSENT_VERSION_FIELD)->isEmpty());
    }
    // Users aren't re-saved, so other save effects (here the changed time)
    // don't run.
    $this->assertSame($changed, $storage->loadUnchanged($deny->id())->getChangedTime());

    // Stamping works again after the reset.
    $user = $storage->loadUnchanged($deny->id());
    $user->set(ProfileService::CONSENT_FIELD, 'allow');
    $user->save();
    $this->assertSame('allow', $svc->consentState($user));
  }

  /**
   * Clearing an outdated choice, as the form's N/A does, asks nothing.
   */
  public function testClearingAnOutdatedChoiceLeavesTheUserUnasked(): void {
    $this->installConsentField();
    $user = User::create(['name' => 'clear']);
    $user->set(ProfileService::CONSENT_FIELD, 'allow');
    $user->save();
    $user->set(ProfileService::CONSENT_VERSION_FIELD, ProfileService::CONSENT_VERSION - 1);
    $user->set(ProfileService::CONSENT_ASKED_FIELD, NULL);
    $user->save();

    $user->set(ProfileService::CONSENT_FIELD, NULL);
    $user->save();

    $svc = $this->container->get('access_affinitygroup.profile');
    $this->assertSame('unset', $svc->consentState($user));
    $this->assertNull($svc->askedAt($user));
    $this->assertTrue($user->get(ProfileService::CONSENT_ASKED_FIELD)->isEmpty());
  }

  /**
   * The user form shows an outdated choice as N/A and stamps form choices.
   */
  public function testUserFormShowsAnOutdatedChoiceAsUnanswered(): void {
    $this->createUserFormDisplay();
    $this->installConsentField();
    $user = User::create(['name' => 'formuser', 'mail' => 'formuser@example.com']);
    $user->set(ProfileService::CONSENT_FIELD, 'deny');
    $user->save();
    $user->set(ProfileService::CONSENT_VERSION_FIELD, ProfileService::CONSENT_VERSION - 1);
    $user->save();
    $this->container->get('current_user')->setAccount($user);

    $form = $this->container->get('entity.form_builder')->getForm($user, 'default');
    $this->assertSame('_none', $form[ProfileService::CONSENT_FIELD]['widget']['#default_value']);
    $this->assertContains('_access_affinitygroup_consent_entity_builder', $form['#entity_builders']);

    // A current choice keeps its own default.
    $fresh = User::create(['name' => 'formfresh', 'mail' => 'formfresh@example.com']);
    $fresh->set(ProfileService::CONSENT_FIELD, 'allow');
    $fresh->save();
    $form = $this->container->get('entity.form_builder')->getForm($fresh, 'default');
    $this->assertSame('allow', $form[ProfileService::CONSENT_FIELD]['widget']['#default_value']);
  }

}
