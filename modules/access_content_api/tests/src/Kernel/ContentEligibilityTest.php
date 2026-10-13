<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\access_content_api\ContentEligibility;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

/**
 * Kernel tests for the shared ContentEligibility service.
 *
 * @group access_content_api
 */
class ContentEligibilityTest extends ContentApiKernelTestBase {

  /**
   * The service under test.
   */
  private ContentEligibility $eligibility;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->eligibility = \Drupal::service('access_content_api.eligibility');
  }

  /**
   * The configured support domain id and text view mode are exposed.
   */
  public function testConfigAccessors(): void {
    $this->assertSame('amp_cyberinfrastructure_org', $this->eligibility->getSupportDomainId());
    $this->assertSame('text', $this->eligibility->getTextViewMode());
  }

  /**
   * Overriding config changes the resolved values (proves config-driven).
   */
  public function testConfigOverride(): void {
    \Drupal::configFactory()->getEditable('access_content_api.settings')
      ->set('support_domain_id', 'other_domain')
      ->set('text_view_mode', 'custom_mode')
      ->save();
    // Re-fetch the service so it reads the updated config.
    $eligibility = \Drupal::service('access_content_api.eligibility');
    $this->assertSame('other_domain', $eligibility->getSupportDomainId());
    $this->assertSame('custom_mode', $eligibility->getTextViewMode());
  }

  /**
   * hasTextViewMode reflects whether the bundle has the configured mode.
   */
  public function testHasTextViewMode(): void {
    $this->assertTrue($this->eligibility->hasTextViewMode('page'));
    $this->assertFalse($this->eligibility->hasTextViewMode('nonexistent_bundle'));
  }

  /**
   * isOnSupportDomain is TRUE for a support-domain node, FALSE otherwise.
   */
  public function testIsOnSupportDomain(): void {
    $onSupport = $this->createPage();
    $this->assertTrue($this->eligibility->isOnSupportDomain($onSupport));

    $offSupport = $this->createPage(['field_domain_access' => []]);
    $this->assertFalse($this->eligibility->isOnSupportDomain($offSupport));
  }

  /**
   * isOnSupportDomain is TRUE for an all-affiliates page (no explicit domain).
   */
  public function testIsOnSupportDomainAllAffiliates(): void {
    $node = $this->createPage([
      'field_domain_access' => [],
      'field_domain_all_affiliates' => 1,
    ]);
    $this->assertTrue($this->eligibility->isOnSupportDomain($node));
  }

  /**
   * supportDomainUrl builds an absolute URL on the support domain host.
   */
  public function testSupportDomainUrl(): void {
    $this->assertSame(
      'https://support.access-ci.org/some/path',
      $this->eligibility->supportDomainUrl('/some/path')
    );
  }

  /**
   * The text and index bundle lists follow the configured displays.
   */
  public function testTextAndIndexBundles(): void {
    $this->createAffinityGroupBundle();
    $this->createTextBundle('access_news', ['body' => 'text_long']);
    $this->createTextBundle('article', ['body' => 'text_long']);
    EntityViewDisplay::load('node.article.text')->delete();

    $text = $this->eligibility->getTextBundles();
    sort($text);
    $this->assertSame(['access_news', 'affinity_group', 'page'], $text);

    $index = $this->eligibility->getIndexBundles();
    sort($index);
    $this->assertSame(['affinity_group', 'page'], $index);
    $this->assertSame(['access_news'], ContentEligibility::INDEX_EXCLUDED_BUNDLES);
    $this->assertSame(
      ['match_engagement' => ['field' => 'field_status', 'values' => ['in_progress', 'complete']]],
      ContentEligibility::INDEX_STATE_ALLOWLIST
    );
  }

  /**
   * isIndexable honors the excluded bundles and the state allowlist.
   */
  public function testIsIndexable(): void {
    $this->createMatchBundle();
    $this->createTextBundle('access_news', ['body' => 'text_long']);

    $this->assertTrue($this->eligibility->isIndexable($this->createPage()));
    $this->assertFalse($this->eligibility->isIndexable($this->createContentNode('access_news')));
    foreach (['in_progress' => TRUE, 'complete' => TRUE, 'declined' => FALSE, 'in_review' => FALSE] as $state => $expected) {
      $node = $this->createContentNode('match_engagement', ['field_status' => $state]);
      $this->assertSame($expected, $this->eligibility->isIndexable($node), $state);
    }
    // A match with no status at all is not in the allowlist.
    $this->assertFalse($this->eligibility->isIndexable($this->createContentNode('match_engagement')));
  }

  /**
   * isPrivate is TRUE only for an affinity group flagged private.
   */
  public function testIsPrivate(): void {
    $this->createAffinityGroupBundle();

    $this->assertTrue($this->eligibility->isPrivate($this->createContentNode('affinity_group', ['field_ag_private' => 1])));
    $this->assertFalse($this->eligibility->isPrivate($this->createContentNode('affinity_group', ['field_ag_private' => 0])));
    $this->assertFalse($this->eligibility->isPrivate($this->createContentNode('affinity_group')));
    // A bundle without the field is never private.
    $this->assertFalse($this->eligibility->isPrivate($this->createPage()));
  }

}
