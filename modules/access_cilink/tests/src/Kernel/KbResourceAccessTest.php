<?php

namespace Drupal\Tests\access_cilink\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Kernel tests for the KB resource detail page access gate.
 *
 * Requests go through the HTTP kernel so route access runs for real. The test
 * module access_cilink_test mimics the private affinity group rule of
 * hook_node_access.
 *
 * @group access_cilink
 */
class KbResourceAccessTest extends KbResourceKernelTestBase {

  /**
   * The two detail routes under test.
   */
  const PATHS = ['/knowledge-base/resources/%d', '/ci-links/%d'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    NodeType::create(['type' => 'affinity_group', 'name' => 'Affinity group'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_resources_entity_reference',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'webform_submission'],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_ag_private',
      'entity_type' => 'node',
      'type' => 'boolean',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_ag_private_users',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'user'],
    ])->save();
    foreach (['field_resources_entity_reference', 'field_ag_private', 'field_ag_private_users'] as $name) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'affinity_group',
        'label' => $name,
      ])->save();
    }
  }

  /**
   * Asserts the status of both detail routes.
   */
  protected function assertStatus(int $expected, WebformSubmission $submission, string $host = self::SUPPORT_HOST, $user = NULL): void {
    foreach (self::PATHS as $pattern) {
      $path = sprintf($pattern, $submission->id());
      $response = $this->get($path, $host, $user);
      $this->assertSame($expected, $response->getStatusCode(), "$path as " . ($user ? $user->getAccountName() : 'anonymous'));
    }
  }

  /**
   * Creates a private affinity group referencing a submission.
   *
   * @param \Drupal\webform\Entity\WebformSubmission $submission
   *   The referenced resource.
   * @param \Drupal\user\Entity\User[] $members
   *   Users allowed to view the private group.
   */
  protected function createPrivateGroup(WebformSubmission $submission, array $members): Node {
    $node = Node::create([
      'type' => 'affinity_group',
      'title' => 'Private group',
      'status' => 1,
      'field_ag_private' => 1,
      'field_ag_private_users' => array_map(fn($user) => ['target_id' => $user->id()], $members),
      'field_resources_entity_reference' => [['target_id' => $submission->id()]],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Anonymous can open approved, non-private resources on any domain.
   */
  public function testAnonymousCanOpenPublicResource(): void {
    $this->assertStatus(200, $this->createResource());
    $other_only = $this->createResource(['domain' => [$this->otherRegion->id()]]);
    // Support host, resource listed only on the other domain.
    $this->assertStatus(200, $other_only);
    // An approved resource with no private value is public.
    $this->assertStatus(200, $this->createResource(['private' => NULL]));
  }

  /**
   * Anonymous gets 403 on unapproved and private resources.
   */
  public function testAnonymousForbiddenOnHiddenResources(): void {
    $this->assertStatus(403, $this->createResource(['approved' => 0]));
    $this->assertStatus(403, $this->createResource(['private' => 1]));
  }

  /**
   * An authenticated stranger is also forbidden.
   */
  public function testAuthenticatedStrangerForbidden(): void {
    $stranger = $this->createUser();
    $this->assertStatus(403, $this->createResource(['approved' => 0]), self::SUPPORT_HOST, $stranger);
  }

  /**
   * The owner, administrators and kb_pm can open an unapproved resource.
   */
  public function testOwnerAdministratorAndKbPmAllowed(): void {
    $owner = $this->createUser();
    $submission = $this->createResource(['approved' => 0], (int) $owner->id());
    $this->assertStatus(200, $submission, self::SUPPORT_HOST, $owner);
    $this->assertStatus(200, $submission, self::SUPPORT_HOST, $this->createUser(['administrator']));
    $this->assertStatus(200, $submission, self::SUPPORT_HOST, $this->createUser(['kb_pm']));
    // The owner can also open their own private resource.
    $private = $this->createResource(['private' => 1], (int) $owner->id());
    $this->assertStatus(200, $private, self::SUPPORT_HOST, $owner);
  }

  /**
   * A missing submission is not forbidden, so the controller reports it.
   */
  public function testMissingSubmissionIsNotForbidden(): void {
    $response = $this->get('/knowledge-base/resources/99999');
    $this->assertSame(200, $response->getStatusCode());
    // The access checker allows it, leaving the "No KB Resource found."
    // message to the controller (replaced by a stub in this test).
    $result = $this->container->get('access_cilink.kb_resource_access')
      ->access('99999', new AnonymousUserSession());
    $this->assertTrue($result->isAllowed());
  }

  /**
   * The redirect route is open and only redirects to the gated page.
   */
  public function testRedirectRouteIsOpen(): void {
    $routes = $this->container->get('router.route_provider')->getRoutesByNames(['access_cilink.cilink_redirect']);
    $route = reset($routes);
    $this->assertSame('TRUE', $route->getRequirement('_access'));
    // The page it redirects to is gated, so an open redirect leaks nothing.
    $submission = $this->createResource(['approved' => 0]);
    $this->assertStatus(403, $submission);
  }

  /**
   * Affinity group exception: members only, and only when allowed on the AG.
   */
  public function testPrivateAffinityGroupException(): void {
    $member = $this->createUser();
    $non_member = $this->createUser();

    $allowed = $this->createResource([
      'approved' => 0,
      'resource_allowed_on_affinity_group' => 1,
    ]);
    $this->createPrivateGroup($allowed, [$member]);

    $this->assertStatus(200, $allowed, self::SUPPORT_HOST, $member);
    $this->assertStatus(403, $allowed, self::SUPPORT_HOST, $non_member);
    $this->assertStatus(403, $allowed);

    // The exception overrides private too.
    $private = $this->createResource([
      'private' => 1,
      'resource_allowed_on_affinity_group' => 1,
    ]);
    $this->createPrivateGroup($private, [$member]);
    $this->assertStatus(200, $private, self::SUPPORT_HOST, $member);
    $this->assertStatus(403, $private, self::SUPPORT_HOST, $non_member);
  }

  /**
   * Without resource_allowed_on_affinity_group the AG member gets 403.
   */
  public function testAffinityGroupMemberForbiddenWhenNotAllowedOnGroup(): void {
    $member = $this->createUser();
    $submission = $this->createResource([
      'approved' => 0,
      'resource_allowed_on_affinity_group' => 0,
    ]);
    $this->createPrivateGroup($submission, [$member]);

    $this->assertStatus(403, $submission, self::SUPPORT_HOST, $member);
  }

  /**
   * Approving a 403'd submission flips the same anonymous request to 200.
   *
   * Dynamic and internal page caches do not store 403 responses, so the
   * invalidation contract is asserted on the access result itself: it carries
   * the webform_submission:{sid} tag, and re-checking after the save is 200.
   */
  public function testApprovingInvalidatesForbiddenResult(): void {
    $submission = $this->createResource(['approved' => 0]);
    $this->assertStatus(403, $submission);

    $result = $this->container->get('access_cilink.kb_resource_access')
      ->access($submission->id(), new AnonymousUserSession());
    $this->assertTrue($result->isForbidden());
    $this->assertContains('webform_submission:' . $submission->id(), $result->getCacheTags());
    $this->assertContains('user.roles', $result->getCacheContexts());
    $this->assertContains('user', $result->getCacheContexts());
    $this->assertContains('node_list:affinity_group', $result->getCacheTags());

    $submission->setElementData('approved', 1);
    $submission->save();

    $this->assertStatus(200, $submission);

    // A public result does not vary per user.
    $result = $this->container->get('access_cilink.kb_resource_access')
      ->access($submission->id(), new AnonymousUserSession());
    $this->assertTrue($result->isAllowed());
    $this->assertContains('webform_submission:' . $submission->id(), $result->getCacheTags());
    $this->assertNotContains('user', $result->getCacheContexts());
  }

  /**
   * Unapproving a cached 200 flips it to 403 via the route access tags.
   *
   * The 200 is stored by the internal page cache. The route access result's
   * webform_submission:{sid} tag is merged onto the response, so saving the
   * submission drops the cached page.
   */
  public function testUnapprovingInvalidatesCachedPage(): void {
    $submission = $this->createResource();
    $path = '/knowledge-base/resources/' . $submission->id();
    $this->assertSame('MISS', $this->get($path)->headers->get('X-Drupal-Cache'));
    $this->assertSame('HIT', $this->get($path)->headers->get('X-Drupal-Cache'));

    $submission->setElementData('approved', 0);
    $submission->save();

    $this->assertSame(403, $this->get($path)->getStatusCode());
  }

}
