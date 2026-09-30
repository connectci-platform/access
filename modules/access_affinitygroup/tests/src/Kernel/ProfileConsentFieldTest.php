<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Tests the assistant-personalization consent field on the user entity.
 *
 * @group access_affinitygroup
 */
class ProfileConsentFieldTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['system', 'user', 'field', 'options', 'key', 'access', 'access_affinitygroup'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConsentField();
  }

  /**
   * Builds the consent field directly, exercising its shape.
   *
   * Does not use installConfig(['access_affinitygroup']), which throws
   * SchemaIncompleteException for this module. The shipped config/install
   * YAMLs are how the field reaches a real site via drush cim; that path is
   * covered by manual verification, not by this kernel test.
   */
  private function installConsentField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_assistant_consent',
      'entity_type' => 'user',
      'type' => 'list_string',
      'cardinality' => 1,
      'settings' => [
        'allowed_values' => [
          'allow' => 'Allow',
          'deny' => 'Deny',
        ],
      ],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_assistant_consent',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Let the ACCESS assistant personalize answers using my profile',
      'required' => FALSE,
    ])->save();
  }

  /**
   * Tests that a new user's consent field defaults to empty (unset).
   */
  public function testConsentFieldDefaultsEmptyMeaningUnset(): void {
    $user = User::create(['name' => 'tester']);
    $user->save();
    $this->assertTrue($user->get('field_assistant_consent')->isEmpty());
  }

  /**
   * Tests that the consent field accepts both allowed values.
   */
  public function testConsentFieldAcceptsAllowAndDeny(): void {
    $user = User::create(['name' => 'tester2']);
    $user->set('field_assistant_consent', 'allow');
    $user->save();
    $this->assertSame('allow', $user->get('field_assistant_consent')->value);
    $user->set('field_assistant_consent', 'deny');
    $user->save();
    $this->assertSame('deny', $user->get('field_assistant_consent')->value);
  }

}
