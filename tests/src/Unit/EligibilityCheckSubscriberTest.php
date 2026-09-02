<?php

namespace Drupal\Tests\access\Unit;

use Drupal\access\EligibilityState;
use Drupal\access\EventSubscriber\EligibilityCheckSubscriber;
use Drupal\access_affinitygroup\Service\AllocationsClient;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\user\UserInterface;
use Drupal\Tests\UnitTestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @coversDefaultClass \Drupal\access\EventSubscriber\EligibilityCheckSubscriber
 * @group access
 */
class EligibilityCheckSubscriberTest extends UnitTestCase {

  use ProphecyTrait;

  private function makeEvent(Request $request): RequestEvent {
    $kernel = $this->prophesize(HttpKernelInterface::class)->reveal();
    return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
  }

  private function makeRequestWithSession(array $sessionData = []): Request {
    $request = Request::create('/');
    $session = new Session(new MockArraySessionStorage());
    foreach ($sessionData as $k => $v) {
      $session->set($k, $v);
    }
    $request->setSession($session);
    return $request;
  }

  private function mockCurrentUser(bool $authenticated, string $accountName = 'apasquale@access-ci.org', int $uid = 5): AccountProxyInterface {
    $currentUser = $this->prophesize(AccountProxyInterface::class);
    $currentUser->isAuthenticated()->willReturn($authenticated);
    $currentUser->id()->willReturn($uid);
    return $currentUser->reveal();
  }

  private function mockUserStorage(string $accountName): EntityTypeManagerInterface {
    $user = $this->prophesize(UserInterface::class);
    $user->getAccountName()->willReturn($accountName);
    $storage = $this->prophesize(EntityStorageInterface::class);
    $storage->load(5)->willReturn($user->reveal());
    $etm = $this->prophesize(EntityTypeManagerInterface::class);
    $etm->getStorage('user')->willReturn($storage->reveal());
    return $etm->reveal();
  }

  public function testAnonymousUserSkipsCheck(): void {
    $allocations = $this->prophesize(AllocationsClient::class);
    $allocations->getEligibilityForUser(\Prophecy\Argument::any())->shouldNotBeCalled();
    $state = new EligibilityState();
    $etm = $this->prophesize(EntityTypeManagerInterface::class)->reveal();

    $subscriber = new EligibilityCheckSubscriber(
      $this->mockCurrentUser(FALSE),
      $etm,
      $allocations->reveal(),
      $state
    );
    $subscriber->onRequest($this->makeEvent($this->makeRequestWithSession()));

    $this->assertFalse($state->isKnown());
  }

  public function testNonAccessAccountNameSkipsCheck(): void {
    $allocations = $this->prophesize(AllocationsClient::class);
    $allocations->getEligibilityForUser(\Prophecy\Argument::any())->shouldNotBeCalled();
    $state = new EligibilityState();

    $subscriber = new EligibilityCheckSubscriber(
      $this->mockCurrentUser(TRUE, 'local-user'),
      $this->mockUserStorage('local-user'),
      $allocations->reveal(),
      $state
    );
    $subscriber->onRequest($this->makeEvent($this->makeRequestWithSession()));

    $this->assertFalse($state->isKnown());
  }

  public function testSessionCachedEligibleSkipsApi(): void {
    $allocations = $this->prophesize(AllocationsClient::class);
    $allocations->getEligibilityForUser(\Prophecy\Argument::any())->shouldNotBeCalled();
    $state = new EligibilityState();

    $subscriber = new EligibilityCheckSubscriber(
      $this->mockCurrentUser(TRUE),
      $this->mockUserStorage('apasquale@access-ci.org'),
      $allocations->reveal(),
      $state
    );
    $subscriber->onRequest($this->makeEvent($this->makeRequestWithSession(['access_eligibility' => 'eligible'])));

    $this->assertTrue($state->isKnown());
    $this->assertTrue($state->isEligible());
  }

  public function testEligibleApiResponseSetsSessionAndState(): void {
    $allocations = $this->prophesize(AllocationsClient::class);
    $allocations->getEligibilityForUser('apasquale')
      ->willReturn(['eligible' => TRUE, 'reason' => NULL])
      ->shouldBeCalledOnce();
    $state = new EligibilityState();
    $request = $this->makeRequestWithSession();

    $subscriber = new EligibilityCheckSubscriber(
      $this->mockCurrentUser(TRUE),
      $this->mockUserStorage('apasquale@access-ci.org'),
      $allocations->reveal(),
      $state
    );
    $subscriber->onRequest($this->makeEvent($request));

    $this->assertTrue($state->isEligible());
    $this->assertSame('eligible', $request->getSession()->get('access_eligibility'));
  }

  public function testIneligibleApiResponseSetsStateButNotSession(): void {
    $allocations = $this->prophesize(AllocationsClient::class);
    $allocations->getEligibilityForUser('apasquale')
      ->willReturn(['eligible' => FALSE, 'reason' => 'Country of Residence is not set.']);
    $state = new EligibilityState();
    $request = $this->makeRequestWithSession();

    $subscriber = new EligibilityCheckSubscriber(
      $this->mockCurrentUser(TRUE),
      $this->mockUserStorage('apasquale@access-ci.org'),
      $allocations->reveal(),
      $state
    );
    $subscriber->onRequest($this->makeEvent($request));

    $this->assertTrue($state->isKnown());
    $this->assertFalse($state->isEligible());
    $this->assertSame('Country of Residence is not set.', $state->getReason());
    $this->assertFalse($request->getSession()->has('access_eligibility'));
  }

  public function testApiNullLeavesStateUnknown(): void {
    $allocations = $this->prophesize(AllocationsClient::class);
    $allocations->getEligibilityForUser('apasquale')->willReturn(NULL);
    $state = new EligibilityState();
    $request = $this->makeRequestWithSession();

    $subscriber = new EligibilityCheckSubscriber(
      $this->mockCurrentUser(TRUE),
      $this->mockUserStorage('apasquale@access-ci.org'),
      $allocations->reveal(),
      $state
    );
    $subscriber->onRequest($this->makeEvent($request));

    $this->assertFalse($state->isKnown());
    $this->assertFalse($request->getSession()->has('access_eligibility'));
  }

  public function testSubRequestSkipsCheck(): void {
    $allocations = $this->prophesize(AllocationsClient::class);
    $allocations->getEligibilityForUser(\Prophecy\Argument::any())->shouldNotBeCalled();
    $state = new EligibilityState();
    $kernel = $this->prophesize(HttpKernelInterface::class)->reveal();
    $request = $this->makeRequestWithSession();
    $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

    $subscriber = new EligibilityCheckSubscriber(
      $this->mockCurrentUser(TRUE),
      $this->mockUserStorage('apasquale@access-ci.org'),
      $allocations->reveal(),
      $state
    );
    $subscriber->onRequest($event);

    $this->assertFalse($state->isKnown());
  }

  public function testUserEntityNotFoundSkipsCheck(): void {
    $allocations = $this->prophesize(AllocationsClient::class);
    $allocations->getEligibilityForUser(\Prophecy\Argument::any())->shouldNotBeCalled();
    $state = new EligibilityState();
    $storage = $this->prophesize(EntityStorageInterface::class);
    $storage->load(5)->willReturn(NULL);
    $etm = $this->prophesize(EntityTypeManagerInterface::class);
    $etm->getStorage('user')->willReturn($storage->reveal());

    $subscriber = new EligibilityCheckSubscriber(
      $this->mockCurrentUser(TRUE),
      $etm->reveal(),
      $allocations->reveal(),
      $state
    );
    $subscriber->onRequest($this->makeEvent($this->makeRequestWithSession()));

    $this->assertFalse($state->isKnown());
  }

}
