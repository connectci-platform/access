<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\access_affinitygroup\Controller\ProfileController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\access_affinitygroup\Traits\AssistantConsentFieldsTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests GET /api/1.0/profile's consent gating and profile shape.
 *
 * @group access_affinitygroup
 */
class ProfileControllerTest extends KernelTestBase {

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
   * Invokes the controller as if acting_user_uid had already been resolved.
   *
   * The real ActingUserAccess gate is proven separately in its own test; here
   * the attribute is set directly on the request.
   *
   * @param \Drupal\user\Entity\User $user
   *   The acting user.
   * @param array<string, string> $query
   *   Query string parameters.
   *
   * @return array<string, mixed>
   *   The decoded JSON response.
   */
  private function invoke(User $user, array $query = []): array {
    $controller = ProfileController::create($this->container);
    $request = Request::create('/api/1.0/profile', 'GET', $query);
    $request->attributes->set('acting_user_uid', (int) $user->id());
    return json_decode($controller->get($request)->getContent(), TRUE);
  }

  /**
   * Tests that an unset consent returns only {"consent":"unset"}.
   */
  public function testUnsetReturnsConsentOnlyNoProfile(): void {
    $user = User::create(['name' => 'u']);
    $user->save();
    $data = $this->invoke($user);
    $this->assertSame('unset', $data['consent']);
    $this->assertArrayNotHasKey('first_name', $data);
  }

  /**
   * Tests that a denied consent returns only {"consent":"deny"}.
   */
  public function testDenyReturnsConsentOnlyNoProfile(): void {
    $user = User::create(['name' => 'u2']);
    $user->set('field_assistant_consent', 'deny');
    $user->save();
    $data = $this->invoke($user);
    $this->assertSame('deny', $data['consent']);
    $this->assertArrayNotHasKey('first_name', $data);
  }

  /**
   * Tests that an allowed consent returns the profile with real values.
   */
  public function testAllowReturnsProfileWithRealValues(): void {
    $user = User::create(['name' => 'u3']);
    $user->set('field_assistant_consent', 'allow');
    $user->set('field_user_first_name', 'Ada');
    $user->set('field_academic_status', 'phd');
    $user->save();
    $data = $this->invoke($user);
    $this->assertSame('allow', $data['consent']);
    $this->assertSame('Ada', $data['first_name']);
    $this->assertSame('PhD student', $data['academic_status']);
    $this->assertArrayHasKey('allocated_resources', $data);
  }

  /**
   * Tests that a missing acting user returns 403, not a user-load attempt.
   */
  public function testBadUidReturns403NotUserLoad(): void {
    $controller = ProfileController::create($this->container);
    $request = Request::create('/api/1.0/profile');
    $request->attributes->set('acting_user_uid', 0);
    $response = $controller->get($request);
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Every response carries consent and asked_at, even with no choice yet.
   */
  public function testUnsetResponseIsConsentAndNullAskedAt(): void {
    $user = User::create(['name' => 'shape']);
    $user->save();
    $this->assertSame(['consent' => 'unset', 'asked_at' => NULL], $this->invoke($user));
  }

  /**
   * An allow response reports when the choice was made.
   */
  public function testAllowReportsAskedAt(): void {
    $user = User::create(['name' => 'when']);
    $user->set('field_assistant_consent', 'allow');
    $user->save();
    $data = $this->invoke($user);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['asked_at']);
  }

  /**
   * With once=1 an unset user's profile fields are returned for one answer.
   */
  public function testOnceReturnsProfileWhenUnset(): void {
    $user = User::create(['name' => 'once']);
    $user->set('field_user_first_name', 'Grace');
    $user->save();
    $data = $this->invoke($user, ['once' => '1']);
    $this->assertSame('unset', $data['consent']);
    $this->assertSame('Grace', $data['first_name']);
    $this->assertArrayHasKey('allocated_resources', $data);
  }

  /**
   * With once=1 a user who said deny still gets no profile fields.
   */
  public function testOnceNeverReturnsProfileWhenDenied(): void {
    $user = User::create(['name' => 'oncedeny']);
    $user->set('field_assistant_consent', 'deny');
    $user->set('field_user_first_name', 'Grace');
    $user->save();
    $data = $this->invoke($user, ['once' => '1']);
    $this->assertSame('deny', $data['consent']);
    $this->assertArrayNotHasKey('first_name', $data);
  }

  /**
   * An allow stored under an older consent version is unset and unshared.
   */
  public function testOlderVersionAllowReturnsUnsetWithoutProfile(): void {
    $user = User::create(['name' => 'stale']);
    $user->set('field_assistant_consent', 'allow');
    $user->save();
    $user->set('field_assistant_consent_version', 0);
    $user->save();
    $data = $this->invoke($user);
    $this->assertSame(['consent' => 'unset', 'asked_at' => NULL], $data);
  }

  /**
   * The response is private and never cached.
   */
  public function testResponseIsPrivateAndUncached(): void {
    $user = User::create(['name' => 'cache']);
    $user->save();
    $controller = ProfileController::create($this->container);
    $request = Request::create('/api/1.0/profile');
    $request->attributes->set('acting_user_uid', (int) $user->id());
    $response = $controller->get($request);
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertSame('0', $response->headers->getCacheControlDirective('max-age'));
  }

}
