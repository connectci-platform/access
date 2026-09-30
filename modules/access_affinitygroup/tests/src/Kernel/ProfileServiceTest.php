<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Tests ProfileService's mapping of a user entity to the PRIME profile.
 *
 * @group access_affinitygroup
 */
class ProfileServiceTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'node',
    'text',
    'filter',
    'key',
    'access',
    'operations_cider',
    'access_affinitygroup',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConsentField();
    $this->installPrimeFields();
    $this->installResourceBundle();
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
   * Builds the PRIME-tier user fields as fixtures.
   *
   * Mirrors how these fields exist on the real site's user bundle, without
   * relying on installConfig() (see installConsentField() docblock).
   */
  private function installPrimeFields(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_user_first_name',
      'entity_type' => 'user',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_user_first_name',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'First Name',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_user_last_name',
      'entity_type' => 'user',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_user_last_name',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Last Name',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_user_preferred_pronouns',
      'entity_type' => 'user',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_user_preferred_pronouns',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Preferred pronouns',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_academic_status',
      'entity_type' => 'user',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'first' => '1st year undergraduate',
          'second' => '2nd year undergraduate',
          'third' => '3rd year undergraduate',
          'fourth' => '4th year undergraduate',
          'masters' => 'Masters student',
          'phd' => 'PhD student',
          'postdoc' => 'Postdoctoral student',
          'fellow' => 'Fellow',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_academic_status',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Academic Status',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_is_cc',
      'entity_type' => 'user',
      'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_is_cc',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => "I'm a Campus Champion",
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_cider_resources',
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_cider_resources',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Allocated Resources',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_access_organization',
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_access_organization',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Organization',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_institution',
      'entity_type' => 'user',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_institution',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Other Institution',
    ])->save();
  }

  /**
   * Builds the CiDeR resource node bundle used by org refs and allocations.
   */
  private function installResourceBundle(): void {
    NodeType::create(['type' => 'access_active_resources_from_cid', 'name' => 'Resource'])->save();
    foreach (['field_rp_display_name', 'field_cider_short_name', 'field_access_global_resource_id'] as $name) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'access_active_resources_from_cid',
      ])->save();
    }
  }

  /**
   * Tests that an empty consent field resolves to 'unset'.
   */
  public function testConsentStateEmptyIsUnset(): void {
    $user = User::create(['name' => 'u']);
    $user->save();
    $this->assertSame('unset', $this->container->get('access_affinitygroup.profile')->consentState($user));
  }

  /**
   * Tests that a saved consent value is returned as-is.
   */
  public function testConsentStateReflectsSavedValue(): void {
    $user = User::create(['name' => 'u2']);
    $user->set('field_assistant_consent', 'allow');
    $user->save();
    $this->assertSame('allow', $this->container->get('access_affinitygroup.profile')->consentState($user));
  }

  /**
   * Tests that academic status resolves to the allowed-values label.
   */
  public function testAcademicStatusReturnsLabelNotKey(): void {
    $user = User::create(['name' => 'u3']);
    $user->set('field_academic_status', 'phd');
    $user->save();
    $profile = $this->container->get('access_affinitygroup.profile')->primeProfile($user);
    $this->assertSame('PhD student', $profile['academic_status']);
  }

  /**
   * Tests that a whitespace-only string field resolves to NULL.
   */
  public function testWhitespaceStringResolvesToNull(): void {
    $user = User::create(['name' => 'u4']);
    $user->set('field_user_first_name', '   ');
    $user->save();
    $profile = $this->container->get('access_affinitygroup.profile')->primeProfile($user);
    $this->assertNull($profile['first_name']);
  }

  /**
   * Tests that a missing organization reference falls back to institution.
   */
  public function testOrganizationFallsBackToInstitutionWhenNoOrgRef(): void {
    $user = User::create(['name' => 'u5']);
    $user->set('field_institution', 'Rutgers University');
    $user->save();
    $profile = $this->container->get('access_affinitygroup.profile')->primeProfile($user);
    $this->assertSame('Rutgers University', $profile['organization']);
  }

  /**
   * Tests that the "Other" org node falls back to the institution field.
   */
  public function testOrganizationOtherNodeUsesInstitution(): void {
    // The org ref resolves to the "Other" node, but the real answer is the
    // free-text institution (mirrors MentorshipPersonBlock).
    $other = Node::create(['type' => 'access_active_resources_from_cid', 'title' => 'Other']);
    $other->save();
    $user = User::create(['name' => 'u5b']);
    $user->set('field_access_organization', [$other->id()]);
    $user->set('field_institution', 'Rutgers University');
    $user->save();
    $profile = $this->container->get('access_affinitygroup.profile')->primeProfile($user);
    $this->assertSame('Rutgers University', $profile['organization']);
  }

  /**
   * Tests that a real org node's label is used over the institution field.
   */
  public function testOrganizationRealNodeUsesNodeName(): void {
    $org = Node::create(['type' => 'access_active_resources_from_cid', 'title' => 'NCSA']);
    $org->save();
    $user = User::create(['name' => 'u5c']);
    $user->set('field_access_organization', [$org->id()]);
    $user->set('field_institution', 'ignored-when-org-present');
    $user->save();
    $profile = $this->container->get('access_affinitygroup.profile')->primeProfile($user);
    $this->assertSame('NCSA', $profile['organization']);
  }

  /**
   * Tests that allocated resources resolve to display-name/slug pairs.
   */
  public function testAllocatedResourcesReturnNameAndSlug(): void {
    $res = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'delta-title-fallback',
      'field_rp_display_name' => 'Delta CPU',
      'field_access_global_resource_id' => 'delta-cpu.ncsa.access-ci.org',
    ]);
    $res->save();
    $user = User::create(['name' => 'u6']);
    $user->set('field_cider_resources', [$res->id()]);
    $user->save();
    $profile = $this->container->get('access_affinitygroup.profile')->primeProfile($user);
    $this->assertCount(1, $profile['allocated_resources']);
    $this->assertSame('delta-cpu.ncsa.access-ci.org', $profile['allocated_resources'][0]['slug']);
    // Proves display-name resolution (field_rp_display_name), not the
    // title fallback.
    $this->assertSame('Delta CPU', $profile['allocated_resources'][0]['name']);
  }

}
