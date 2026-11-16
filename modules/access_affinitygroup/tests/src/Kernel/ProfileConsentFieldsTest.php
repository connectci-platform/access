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
    $this->assertSame('Let the ACCESS Assistant personalize answers using my profile', $field->getLabel());
    $this->assertStringContainsString('your allocation and account information', $field->getDescription());
    $this->assertNotNull(FieldConfig::loadByName('user', 'user', ProfileService::CONSENT_VERSION_FIELD));
    $this->assertNotNull(FieldConfig::loadByName('user', 'user', ProfileService::CONSENT_ASKED_FIELD));

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
    $this->assertSame('Let the ACCESS Assistant personalize answers using my profile', $field->getLabel());
    $this->assertStringStartsWith('When on, the assistant may use your ACCESS profile', $field->getDescription());
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

}
