<?php

namespace Drupal\Tests\access_cilink\Unit;

use Drupal\access_cilink\ResourceUserActions;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\flag\FlagLinkBuilderInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the per-user lazy builders for KB resources.
 *
 * @coversDefaultClass \Drupal\access_cilink\ResourceUserActions
 * @group access_cilink
 */
class ResourceUserActionsTest extends UnitTestCase {

  /**
   * Submission owner ID used in the tests.
   */
  const OWNER_ID = 7;

  /**
   * The current user mock.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The flag link builder mock.
   */
  protected FlagLinkBuilderInterface $linkBuilder;

  /**
   * Flag IDs requested from the link builder.
   *
   * @var string[]
   */
  protected array $builtFlags = [];

  /**
   * Whether the submission loads.
   */
  protected bool $submissionLoads = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->linkBuilder = $this->createMock(FlagLinkBuilderInterface::class);
    $this->linkBuilder->method('build')->willReturnCallback(function ($type, $id, $flag_id, $mode) {
      $this->builtFlags[] = $flag_id;
      return ['#theme' => 'flag', '#test_flag_id' => $flag_id];
    });
  }

  /**
   * Builds the service under test.
   */
  protected function service(): ResourceUserActions {
    $webform = $this->createMock(WebformInterface::class);
    $webform->method('id')->willReturn('resource');
    $submission = $this->createMock(WebformSubmissionInterface::class);
    $submission->method('getOwnerId')->willReturn(self::OWNER_ID);
    $submission->method('id')->willReturn(42);
    $submission->method('getWebform')->willReturn($webform);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(fn () => $this->submissionLoads ? $submission : NULL);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('webform_submission')->willReturn($storage);
    $stack = new RequestStack();
    $stack->push(Request::create('/knowledge-base/resources', 'GET', ['f' => ['a']]));

    $service = new ResourceUserActions($this->currentUser, $this->linkBuilder, $etm, $stack);
    $service->setStringTranslation($this->getStringTranslationStub());
    return $service;
  }

  /**
   * Sets up the current user.
   */
  protected function setUser(int $id, array $roles): void {
    $this->currentUser->method('id')->willReturn($id);
    $this->currentUser->method('isAnonymous')->willReturn($id === 0);
    $this->currentUser->method('getRoles')->willReturn($roles);
  }

  /**
   * Finds the first render array element containing a key.
   */
  protected function findKey(array $element, string $key): mixed {
    if (array_key_exists($key, $element)) {
      return $element[$key];
    }
    foreach ($element as $child) {
      if (is_array($child) && ($found = $this->findKey($child, $key)) !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

  /**
   * Collects the report flag IDs found in a render array.
   */
  protected function flagIds(array $build): array {
    $context = $build['#context'] ?? [];
    $ids = [];
    foreach (['outdated', 'not_useful', 'inaccurate'] as $name) {
      if (!empty($context[$name]['#test_flag_id'])) {
        $ids[] = $context[$name]['#test_flag_id'];
      }
    }
    return $ids;
  }

  /**
   * Returns the edit URL path for a build, or NULL.
   */
  protected function editUrl(array $build) {
    $edit = $build['#context']['edit'] ?? [];
    return $edit ? $edit['#url'] : NULL;
  }

  /**
   * @covers ::trustedCallbacks
   */
  public function testTrustedCallbacks(): void {
    $this->assertSame(['likeWidget', 'userActions'], ResourceUserActions::trustedCallbacks());
  }

  /**
   * @covers ::likeWidget
   */
  public function testAnonymousLikeWidgetShowsLoginAndSkipsFlag(): void {
    $this->setUser(0, ['anonymous']);
    $this->linkBuilder = $this->createMock(FlagLinkBuilderInterface::class);
    $this->linkBuilder->expects($this->never())->method('build');
    $build = $this->service()->likeWidget(42, 'listing:access-support');

    $login = $build['#context']['login'];
    $this->assertSame('link', $login['#type']);
    $this->assertSame('Login to like', (string) $login['#title']);
    $options = $login['#url']->getOptions();
    $this->assertSame('/knowledge-base/resources?f%5B0%5D=a', $options['query']['destination']);
    $this->assertContains('hover--underline', $options['attributes']['class']);
    $this->assertContains('user', $build['#cache']['contexts']);
    $this->assertContains('url.query_args', $build['#cache']['contexts']);
  }

  /**
   * @covers ::likeWidget
   */
  public function testAuthenticatedLikeWidgetReturnsFlagRenderArray(): void {
    $this->setUser(9, ['authenticated']);
    $build = $this->service()->likeWidget('42', 'listing:ccmnet');

    $this->assertIsArray($build['#context']['flag']);
    $this->assertSame('upvote', $build['#context']['flag']['#test_flag_id']);
    $this->assertSame('flag', $build['#context']['flag']['#theme']);
    $this->assertSame(['text-decoration-none', 'fw-normal'], $build['#context']['flag']['#attributes']['class']);
    $this->assertSame(['upvote'], $this->builtFlags);
    $this->assertContains('user', $build['#cache']['contexts']);
  }

  /**
   * @covers ::userActions
   */
  public function testAnonymousUserActionsAreEmpty(): void {
    $this->setUser(0, ['anonymous']);
    $build = $this->service()->userActions(42, 'listing:access-support');
    $this->assertSame(['#cache' => ['contexts' => ['user', 'url.query_args']]], $build);
    $this->assertSame([], $this->builtFlags);
  }

  /**
   * @covers ::userActions
   */
  public function testOwnerGetsEditAndFlags(): void {
    $this->setUser(self::OWNER_ID, ['authenticated']);
    $build = $this->service()->userActions(42, 'listing:access-support');
    $url = $this->editUrl($build);
    $this->assertNotNull($url);
    $this->assertSame('entity.webform_submission.edit_form', $url->getRouteName());
    $this->assertSame(['webform' => 'resource', 'webform_submission' => 42], $url->getRouteParameters());
    $this->assertSame(['outdated', 'not_useful', 'inaccurate'], $this->flagIds($build));
    $this->assertContains('user', $build['#cache']['contexts']);
  }

  /**
   * @covers ::userActions
   */
  public function testOtherAuthenticatedUserGetsFlagsButNoEdit(): void {
    $this->setUser(99, ['authenticated']);
    $build = $this->service()->userActions(42, 'listing:access-support');
    $this->assertNull($this->editUrl($build));
    $this->assertSame(['outdated', 'not_useful', 'inaccurate'], $this->flagIds($build));
    foreach (['outdated', 'not_useful', 'inaccurate'] as $name) {
      $this->assertSame(['no-underline text-dark-teal hover--underline'], $build['#context'][$name]['#attributes']['class']);
    }
  }

  /**
   * @covers ::userActions
   * @dataProvider privilegedRoleProvider
   */
  public function testPrivilegedRolesGetEdit(string $role): void {
    $this->setUser(99, ['authenticated', $role]);
    $build = $this->service()->userActions(42, 'page:default');
    $this->assertNotNull($this->editUrl($build));
    $this->assertSame(['outdated', 'not_useful', 'inaccurate'], $this->flagIds($build));
  }

  /**
   * Data provider for privileged roles.
   */
  public static function privilegedRoleProvider(): array {
    return [['administrator'], ['kb_pm']];
  }

  /**
   * @covers ::userActions
   */
  public function testMissingSubmissionYieldsNoActions(): void {
    $this->setUser(self::OWNER_ID, ['authenticated']);
    $this->submissionLoads = FALSE;
    $build = $this->service()->userActions(42, 'listing:default');
    $this->assertArrayNotHasKey('#template', $build);
    $this->assertContains('user', $build['#cache']['contexts']);
  }

  /**
   * @covers ::userActions
   * @covers ::likeWidget
   */
  public function testUnknownVariantFallsBackSafely(): void {
    $this->setUser(self::OWNER_ID, ['authenticated']);
    $service = $this->service();
    $build = $service->userActions(42, '"><script>alert(1)</script>');
    $this->assertStringNotContainsString('script', $build['#template']);
    $this->assertStringNotContainsString('script', serialize($build['#context']));
    $like = $service->likeWidget(42, '"><script>');
    $this->assertStringNotContainsString('script', $like['#template']);
  }

}
