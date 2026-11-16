<?php

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\access_affinitygroup\Controller\ProfileConsentController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\access_affinitygroup\Traits\AssistantConsentFieldsTrait;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests POST /api/1.0/profile/consent's write and validation behavior.
 *
 * @group access_affinitygroup
 */
class ProfileConsentControllerTest extends KernelTestBase {

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
    $this->installConsentField();
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

  /**
   * Allow returns the stored value and when it was recorded.
   */
  public function testAllowResponseCarriesAskedAt(): void {
    $user = User::create(['name' => 'shape']);
    $user->save();
    $data = json_decode($this->post($user, json_encode(['consent' => 'allow']))->getContent(), TRUE);
    $this->assertSame('allow', $data['consent']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['asked_at']);
    $this->assertSame(['consent', 'asked_at'], array_keys($data));
  }

  /**
   * Repeating the same choice still records version and a fresh asked time.
   */
  public function testRepeatedChoiceRestampsAskedAt(): void {
    $user = User::create(['name' => 'again']);
    $user->set('field_assistant_consent', 'allow');
    $user->save();
    $user->set('field_assistant_consent_asked', 1000);
    $user->save();
    $this->post($user, json_encode(['consent' => 'allow']));
    $this->assertGreaterThan(1000, (int) User::load($user->id())->get('field_assistant_consent_asked')->value);
  }

  /**
   * Not now on an unset user records the ask and leaves consent unset.
   */
  public function testNotNowWhenUnsetRecordsAsk(): void {
    $user = User::create(['name' => 'later']);
    $user->save();
    $resp = $this->post($user, json_encode(['consent' => 'not_now']));
    $this->assertSame(200, $resp->getStatusCode());
    $data = json_decode($resp->getContent(), TRUE);
    $this->assertSame('unset', $data['consent']);
    $this->assertNotNull($data['asked_at']);

    $svc = $this->container->get('access_affinitygroup.profile');
    $reloaded = User::load($user->id());
    $this->assertSame('unset', $svc->consentState($reloaded));
    $this->assertSame($data['asked_at'], $svc->askedAt($reloaded));
  }

  /**
   * Not now on an older-version allow clears it rather than reviving it.
   */
  public function testNotNowWhenOlderVersionAllowStaysUnset(): void {
    $user = User::create(['name' => 'stale']);
    $user->set('field_assistant_consent', 'allow');
    $user->save();
    $user->set('field_assistant_consent_version', 0);
    $user->save();
    $this->assertSame(200, $this->post($user, json_encode(['consent' => 'not_now']))->getStatusCode());
    $svc = $this->container->get('access_affinitygroup.profile');
    $this->assertSame('unset', $svc->consentState(User::load($user->id())));
  }

  /**
   * Not now conflicts with a choice the user already made.
   *
   * @dataProvider madeChoices
   */
  public function testNotNowConflictsWithMadeChoice(string $choice): void {
    $user = User::create(['name' => 'made']);
    $user->set('field_assistant_consent', $choice);
    $user->save();
    $this->assertSame(409, $this->post($user, json_encode(['consent' => 'not_now']))->getStatusCode());
    $this->assertSame($choice, $this->container->get('access_affinitygroup.profile')->consentState(User::load($user->id())));
  }

  /**
   * Not now checks the stored answer, not a copy loaded before a deny.
   */
  public function testNotNowReadsTheStoredAnswer(): void {
    $user = User::create(['name' => 'race']);
    $user->save();
    // Another request saves deny after this copy was loaded.
    $other = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged($user->id());
    $other->set('field_assistant_consent', 'deny');
    $other->save();

    $this->assertSame(409, $this->post($user, json_encode(['consent' => 'not_now']))->getStatusCode());
    $stored = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged($user->id());
    $this->assertSame('deny', $this->container->get('access_affinitygroup.profile')->consentState($stored));
  }

  /**
   * Choices a user can already have made.
   *
   * @return array<int, array{string}>
   *   One stored choice per case.
   */
  public static function madeChoices(): array {
    return [['allow'], ['deny']];
  }

}
