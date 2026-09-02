<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\access_content_api\Controller\ContentController;
use Drupal\user\Entity\Role;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel test for finding 1: the per-id endpoint enforces node-access.
 *
 * buildResponse() now checks $node->access('view') as the anonymous user, so a
 * node that anonymous cannot view returns 404 instead of leaking its text. The
 * "access content" permission is the cleanest deterministic lever for that
 * decision (revoking it denies anonymous view regardless of domain grants).
 *
 * @group access_content_api
 */
class ContentNodeAccessTest extends ContentApiKernelTestBase {

  /**
   * A node anonymous cannot view returns 404 even if it passes the API guards.
   *
   * The node is published, on the support domain, and has the text view mode,
   * but anonymous lacks view access. The access-checked index would omit it,
   * so byId must 404 it too rather than serve its text.
   */
  public function testByIdReturns404WhenAnonymousCannotView(): void {
    $node = $this->createPage();

    // Revoke the base view permission so anonymous cannot view any node.
    $anonymous = Role::load(Role::ANONYMOUS_ID);
    $anonymous->revokePermission('access content');
    $anonymous->save();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/' . $node->id());
    $response = $controller->byId($request, (int) $node->id());

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * Control case: a viewable support-domain node is still served (200).
   *
   * Confirms the access check does not over-block when anonymous can view.
   */
  public function testByIdServesViewableNode(): void {
    $node = $this->createPage();

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(
      ContentController::class
    );
    $request = Request::create('/api/1.0/content/' . $node->id());
    $response = $controller->byId($request, (int) $node->id());

    $this->assertEquals(200, $response->getStatusCode());
  }

}
