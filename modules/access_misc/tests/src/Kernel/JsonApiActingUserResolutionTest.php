<?php

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\access\AccessIdResolver;
use Drupal\access_misc\EventSubscriber\JsonApiViewsUserParameterSubscriber;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Acting-user resolution on the JSON:API views surface is ACCESS-ID-only.
 *
 * The views-parameter subscriber previously resolved by email and by username.
 * Those are non-ACCESS-ID identity channels with no senders in our stack, so
 * they are gone — resolution goes through the shared AccessIdResolver, same
 * as the MCP gate. (JSON:API writes are disabled, so the POST/PATCH uid
 * subscriber is gone too.)
 *
 * @group access_misc
 */
class JsonApiActingUserResolutionTest extends KernelTestBase {

  // The subscriber is instantiated directly with the resolver, so the
  // access_misc module (whose deps pull in access_events -> content_moderation)
  // does not need enabling. Only user/system are required for the entity API.
  protected static $modules = ['user', 'system'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
    $this->createAuthmapTable();
  }

  /**
   * Creates the openid_connect_authmap table (contrib-owned).
   */
  protected function createAuthmapTable(): void {
    $schema = \Drupal::database()->schema();
    if ($schema->tableExists('openid_connect_authmap')) {
      return;
    }
    $schema->createTable('openid_connect_authmap', [
      'fields' => [
        'aid' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'uid' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'client_name' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
        'sub' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
      ],
      'primary key' => ['aid'],
    ]);
  }

  protected function writeAuthmap(User $user, string $sub, string $client = 'cilogon'): void {
    \Drupal::database()->insert('openid_connect_authmap')
      ->fields([
        'uid' => (int) $user->id(),
        'client_name' => $client,
        'sub' => $sub,
      ])
      ->execute();
  }

  protected function resolver(): AccessIdResolver {
    return new AccessIdResolver(\Drupal::entityTypeManager(), \Drupal::database());
  }

  /**
   * Runs the views-parameter subscriber and returns views-argument[0], if set.
   */
  protected function runViewsParameter(array $headers): ?int {
    $request = Request::create('/jsonapi/views/my_view/page_1', 'GET');
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }

    $subscriber = new JsonApiViewsUserParameterSubscriber($this->resolver());
    $subscriber->onRequest(new RequestEvent(
      \Drupal::service('http_kernel'),
      $request,
      HttpKernelInterface::MAIN_REQUEST
    ));

    $argument = $request->query->all()['views-argument'][0] ?? NULL;
    return $argument === NULL ? NULL : (int) $argument;
  }

  public function testViewsParameterResolvesAccessId(): void {
    $user = User::create(['name' => 'display-name-5', 'mail' => 'd5@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'viewsguy@access-ci.org');

    $this->assertSame((int) $user->id(), $this->runViewsParameter(['X-Acting-User' => 'viewsguy']));
  }

  /**
   * The views subscriber previously matched an email in X-Acting-User. Gone.
   */
  public function testViewsParameterEmailIsInert(): void {
    $user = User::create(['name' => 'display-name-6', 'mail' => 'views@example.com', 'status' => 1]);
    $user->save();
    $this->writeAuthmap($user, 'viewsguy2@access-ci.org');

    $this->assertNull($this->runViewsParameter(['X-Acting-User' => 'views@example.com']));
  }

  /**
   * The views subscriber previously fell back to username. Gone.
   */
  public function testViewsParameterUsernameIsInert(): void {
    $user = User::create(['name' => 'viewsplain', 'mail' => 'vp@example.com', 'status' => 1]);
    $user->save();

    $this->assertNull($this->runViewsParameter(['X-Acting-User' => 'viewsplain']));
  }

  /**
   * A blocked account does not resolve, even with a matching authmap row.
   */
  public function testViewsParameterBlockedUserDoesNotResolve(): void {
    $user = User::create(['name' => 'blocked-display', 'mail' => 'b@example.com', 'status' => 0]);
    $user->save();
    $this->writeAuthmap($user, 'blockedguy@access-ci.org');

    $this->assertNull($this->runViewsParameter(['X-Acting-User' => 'blockedguy']));
  }

}
