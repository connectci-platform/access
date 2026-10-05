<?php

namespace Drupal\Tests\user_profiles\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\UserSession;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Kernel tests for the suspended-user check in hook_entity_presave().
 *
 * Entity_test entities are saved (not users) so the user-specific badge logic
 * in the hook is not triggered.
 *
 * @group user_profiles
 */
class SuspendedUserCheckTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * Modules to enable.
   *
   * The 'flag' module is required because the user_profiles.user_merger
   * service injects the flag service as a hard dependency, so the container
   * will not compile without it.
   *
   * @var array<int, string>
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'flag',
    'entity_test',
    'user_profiles',
  ];

  /**
   * Recorded HTTP transactions.
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $history = [];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', 'Drupal\Core\StreamWrapper\PrivateStream')
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');

    $private = $this->siteDirectory . '/private';
    mkdir($private . '/.keys', 0775, TRUE);
    file_put_contents($private . '/.keys/secrets.json', '{"ramps_api_key":"test-key"}');
    $this->setSetting('file_private_path', $private);

    $this->config('user_profiles.settings')->set('check_suspended_users', TRUE)->save();
    drupal_static_reset('user_profiles_get_account_data_from_api');
  }

  /**
   * Installs a mocked HTTP client and the current ACCESS user.
   *
   * @param array<int, \GuzzleHttp\Psr7\Response> $responses
   *   Queued responses.
   * @param string $name
   *   The current user's account name.
   */
  protected function prepare(array $responses, string $name = 'jdoe@access-ci.org'): void {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $this->setCurrentUser(new UserSession(['uid' => 5, 'name' => $name]));
  }

  /**
   * Saves an entity_test entity.
   */
  protected function saveEntity(): EntityTest {
    $entity = EntityTest::create(['name' => $this->randomMachineName()]);
    $entity->save();
    return $entity;
  }

  /**
   * A batch of saves makes one API call using the bare handle.
   */
  public function testBatchSaveMakesOneCallWithBareHandle(): void {
    // Queue a response per save so an un-memoized extra call is recorded in
    // history; an empty MockHandler queue throws before history records it.
    $this->prepare(array_fill(0, 3, new Response(200, [], '{"isSuspended": false}')));
    for ($i = 0; $i < 3; $i++) {
      $this->saveEntity();
    }
    $this->assertCount(1, $this->history);
    $request = $this->history[0]['request'];
    $this->assertSame('/identity/profiles/v1/people/jdoe', $request->getUri()->getPath());
    $this->assertSame('test-key', $request->getHeaderLine('XA-API-KEY'));
  }

  /**
   * A 404 result is memoized too.
   */
  public function testNotFoundIsCachedToo(): void {
    $this->prepare(array_fill(0, 3, new Response(404, [], '{}')));
    for ($i = 0; $i < 3; $i++) {
      $this->saveEntity();
    }
    $this->assertCount(1, $this->history);
  }

  /**
   * A suspended user cannot save.
   */
  public function testSuspendedUserCannotSave(): void {
    $this->prepare([new Response(200, [], '{"isSuspended": true}')]);
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage("'jdoe@access-ci.org' has been suspended");
    $this->saveEntity();
  }

  /**
   * A non-ACCESS account never triggers an API lookup.
   */
  public function testNonAccessAccountSkipsLookup(): void {
    $this->prepare([], 'localuser');
    $entity = $this->saveEntity();
    $this->assertCount(0, $this->history);
    $this->assertNotNull($entity->id());
  }

  /**
   * Disabling the check skips the API lookup.
   */
  public function testCheckDisabledSkipsLookup(): void {
    $this->config('user_profiles.settings')->set('check_suspended_users', FALSE)->save();
    $this->prepare([]);
    $entity = $this->saveEntity();
    $this->assertCount(0, $this->history);
    $this->assertNotNull($entity->id());
  }

}
