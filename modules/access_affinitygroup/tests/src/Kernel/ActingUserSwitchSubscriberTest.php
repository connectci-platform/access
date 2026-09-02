<?php

declare(strict_types=1);

namespace Drupal\Tests\access_affinitygroup\Kernel;

use Drupal\access_affinitygroup\EventSubscriber\ActingUserSwitchSubscriber;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @covers \Drupal\access_affinitygroup\EventSubscriber\ActingUserSwitchSubscriber
 */
class ActingUserSwitchSubscriberTest extends KernelTestBase {

  use UserCreationTrait;

  // 'key' is required: access_affinitygroup.services.yml hard-injects
  // '@key.repository'; without it the container fails to compile. 'access'
  // provides access.access_id_resolver, which the gate now depends on.
  protected static $modules = ['system', 'user', 'key', 'access', 'access_affinitygroup'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->createAuthmapTable();
  }

  /**
   * Creates the openid_connect_authmap table.
   *
   * ActingUserAccess resolves X-Acting-User against this contrib table only.
   * Just the table is created; enabling openid_connect would drag in
   * file.repository and the rest of its service graph.
   */
  private function createAuthmapTable(): void {
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

  private function subscriber(): ActingUserSwitchSubscriber {
    return new ActingUserSwitchSubscriber(
      \Drupal::service('account_switcher'),
      \Drupal::entityTypeManager(),
    );
  }

  private function requestEvent(Request $request): RequestEvent {
    return new RequestEvent(
      \Drupal::service('http_kernel'),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
    );
  }

  public function testSwitchesToActingUserThenBack(): void {
    $user = $this->createUser([], 'acting');
    $sub = $this->subscriber();
    $request = Request::create('/api/1.0/registrations');
    $request->attributes->set('acting_user_uid', (int) $user->id());
    $sub->onRequest($this->requestEvent($request));
    $this->assertSame((int) $user->id(), (int) \Drupal::currentUser()->id());
    $sub->onTerminate();
    $this->assertSame(0, (int) \Drupal::currentUser()->id());
  }

  public function testNoAttributeMeansNoSwitchAndTerminateIsSafe(): void {
    $sub = $this->subscriber();
    $request = Request::create('/some/public/path');
    $sub->onRequest($this->requestEvent($request));
    $this->assertSame(0, (int) \Drupal::currentUser()->id());
    // Must NOT throw on empty stack.
    $sub->onTerminate();
    $this->assertSame(0, (int) \Drupal::currentUser()->id());
  }

  public function testSubrequestDoesNotSwitch(): void {
    $user = $this->createUser([], 'acting2');
    $sub = $this->subscriber();
    $request = Request::create('/api/1.0/registrations');
    $request->attributes->set('acting_user_uid', (int) $user->id());
    $event = new RequestEvent(\Drupal::service('http_kernel'), $request, HttpKernelInterface::SUB_REQUEST);
    $sub->onRequest($event);
    $this->assertSame(0, (int) \Drupal::currentUser()->id());
    $sub->onTerminate();
    $this->assertSame(0, (int) \Drupal::currentUser()->id());
  }

  /**
   * The load-bearing handoff: the gate sets acting_user_uid and the subscriber
   * reads that SAME attribute off the SAME request and switches.
   */
  public function testGateThenSubscriberHandoff(): void {
    // The gate requires the authenticated account to hold 'mcp_service'.
    $this->createRole([], 'mcp_service');
    $service = $this->createUser([], 'svc');
    $service->addRole('mcp_service');
    $service->save();
    $target = $this->createUser([], 'target');
    // The gate resolves ACCESS IDs via the authmap, not the username.
    \Drupal::database()->insert('openid_connect_authmap')
      ->fields([
        'uid' => (int) $target->id(),
        'client_name' => 'cilogon',
        'sub' => 'target@access-ci.org',
      ])
      ->execute();

    $request = Request::create('/api/1.0/registrations');
    $request->headers->set('X-Acting-User', 'target@access-ci.org');

    $gate = new \Drupal\access_affinitygroup\Access\ActingUserAccess(new \Drupal\access\AccessIdResolver(\Drupal::entityTypeManager(), \Drupal::database()));
    $result = $gate->check($service, $request);
    $this->assertTrue($result->isAllowed());
    $this->assertSame((int) $target->id(), (int) $request->attributes->get('acting_user_uid'));

    $sub = $this->subscriber();
    $sub->onRequest($this->requestEvent($request));
    $this->assertSame((int) $target->id(), (int) \Drupal::currentUser()->id());
    $sub->onTerminate();
    $this->assertSame(0, (int) \Drupal::currentUser()->id());
  }
}
