<?php

namespace Drupal\Tests\cssn\Kernel;

use Drupal\cssn\Controller\CommunityPersonaController;
use Drupal\Tests\access_cilink\Kernel\KbResourceKernelTestBase;

/**
 * Tests the Knowledge Base contributions list on the community persona.
 *
 * Reuses the KB resource fixture from access_cilink. cssn is not enabled
 * (heavy install hooks and dependencies); its controller is autoloaded
 * directly, as in the other cssn kernel tests.
 *
 * @group cssn
 * @group access_cilink
 */
class KnowledgeBaseContribTest extends KbResourceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $loader = $this->container->get('class_loader');
    $loader->addPsr4('Drupal\\cssn\\', dirname(__DIR__, 3) . '/src');
  }

  /**
   * Builds the persona controller from the container.
   */
  protected function controller(): CommunityPersonaController {
    return CommunityPersonaController::create($this->container);
  }

  /**
   * Creates the three submissions, returning their titles keyed by role.
   *
   * @return \Drupal\user\Entity\User
   *   The owner.
   */
  protected function createContributions() {
    $owner = $this->createUser();
    $uid = (int) $owner->id();
    foreach ([
      ['Approved Resource', 1, 0],
      ['Pending Resource', 0, 0],
      ['Private Resource', 1, 1],
    ] as [$title, $approved, $private]) {
      $submission = $this->createResource([
        'title' => $title,
        'approved' => $approved,
        'private' => $private,
      ], $uid);
      // The persona query matches on the submission URI.
      $submission->set('uri', '/form/resource')->save();
    }
    // A submission without a "private" value counts as public.
    $legacy = $this->createResource(['title' => 'Legacy Resource', 'private' => NULL], $uid);
    $legacy->set('uri', '/form/resource')->save();
    return $owner;
  }

  /**
   * The public profile lists only approved, non-private resources.
   */
  public function testPublicListExcludesUnapprovedAndPrivate(): void {
    $owner = $this->createContributions();
    $html = (string) $this->controller()->knowledgeBaseContrib($owner, TRUE);

    $this->assertStringContainsString('Approved Resource', $html);
    $this->assertStringContainsString('Legacy Resource', $html);
    $this->assertStringNotContainsString('Pending Resource', $html);
    $this->assertStringNotContainsString('Private Resource', $html);
    $this->assertStringNotContainsString('(pending approval)', $html);
  }

  /**
   * The public profile shows the empty text when nothing is public.
   */
  public function testPublicListEmptyState(): void {
    $owner = $this->createUser();
    $submission = $this->createResource(['title' => 'Hidden Resource', 'approved' => 0], (int) $owner->id());
    $submission->set('uri', '/form/resource')->save();

    $html = (string) $this->controller()->knowledgeBaseContrib($owner, TRUE);
    $this->assertStringContainsString('No contributions to the Knowledge Base.', $html);
    $this->assertStringNotContainsString('Hidden Resource', $html);
  }

  /**
   * The owner's own view lists everything, labeled with its status.
   */
  public function testOwnerListShowsAllWithLabels(): void {
    $owner = $this->createContributions();
    $html = (string) $this->controller()->knowledgeBaseContrib($owner, FALSE);

    $this->assertStringContainsString('Approved Resource</a>', $html);
    $this->assertStringContainsString('Legacy Resource</a>', $html);
    $this->assertStringContainsString('Pending Resource (pending approval)</a>', $html);
    $this->assertStringContainsString('Private Resource (private)</a>', $html);
  }

}
