<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\access_affinitygroup\Controller\ProfileConsentController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests POST /api/1.0/profile/consent's write and validation behavior.
 *
 * @group access_affinitygroup
 */
class ProfileConsentControllerTest extends KernelTestBase {

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
   * Invokes the controller as if acting_user_uid had already been resolved.
   *
   * The real ActingUserAccess gate is proven separately in its own test; here
   * the attribute is set directly on the request.
   */
  private function post(User $user, string $body): JsonResponse {
    $controller = ProfileConsentController::create($this->container);
    $request = Request::create('/api/1.0/profile/consent', 'POST', [], [], [], [], $body);
    $request->attributes->set('acting_user_uid', (int) $user->id());
    return $controller->post($request);
  }

  /**
   * Tests that posting 'allow' persists to the field.
   */
  public function testWriteAllowPersists(): void {
    $user = User::create(['name' => 'u']);
    $user->save();
    $resp = $this->post($user, json_encode(['consent' => 'allow']));
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertSame('allow', User::load($user->id())->get('field_assistant_consent')->value);
  }

  /**
   * Tests that posting 'deny' persists to the field.
   */
  public function testWriteDenyPersists(): void {
    $user = User::create(['name' => 'u2']);
    $user->save();
    $resp = $this->post($user, json_encode(['consent' => 'deny']));
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertSame('deny', User::load($user->id())->get('field_assistant_consent')->value);
  }

  /**
   * Tests that a value other than allow/deny is rejected with 400.
   */
  public function testInvalidValueRejected(): void {
    $user = User::create(['name' => 'u3']);
    $user->save();
    $this->assertSame(400, $this->post($user, json_encode(['consent' => 'once']))->getStatusCode());
  }

  /**
   * Tests that a body missing the consent key is rejected with 400.
   */
  public function testMissingKeyRejected(): void {
    $user = User::create(['name' => 'u4']);
    $user->save();
    $this->assertSame(400, $this->post($user, json_encode(['foo' => 'bar']))->getStatusCode());
  }

  /**
   * Tests that a non-JSON body is rejected with 400.
   */
  public function testNonJsonBodyRejected(): void {
    $user = User::create(['name' => 'u5']);
    $user->save();
    $this->assertSame(400, $this->post($user, 'not json')->getStatusCode());
  }

  /**
   * Tests that a later deny overrides an earlier allow immediately.
   */
  public function testRequestTimeRevocation(): void {
    // Allow, then deny; a GET-equivalent consentState read reflects it at once.
    $user = User::create(['name' => 'u6']);
    $user->save();
    $this->post($user, json_encode(['consent' => 'allow']));
    $this->post($user, json_encode(['consent' => 'deny']));
    $svc = $this->container->get('access_affinitygroup.profile');
    $this->assertSame('deny', $svc->consentState(User::load($user->id())));
  }

}
