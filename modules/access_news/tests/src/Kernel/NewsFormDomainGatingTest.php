<?php

declare(strict_types=1);

namespace Drupal\Tests\access_news\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\access\Traits\ActiveDomainStubTrait;

/**
 * The news form's domain gating helper.
 *
 * _access_news_apply_domain_gating() hides the field_affiliation widget and
 * the bi-weekly-digest share option everywhere but the ACCESS Support
 * domain, both driven by access.hidden_field_options so the two rules can't
 * drift from each other (see D8-2848). 'domain' is not enabled here, so
 * there is no domain.negotiator unless a test stubs one via
 * ActiveDomainStubTrait. These tests also cover
 * HiddenFieldOptions::isSupportDomain() and ::hidesAffiliationWidget(); no
 * separate service test exists for them.
 *
 * @group access_news
 */
class NewsFormDomainGatingTest extends KernelTestBase {

  use ActiveDomainStubTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    // 'access' provides access.hidden_field_options; it also registers
    // access.eligibility_check_subscriber, which depends on
    // access_affinitygroup.allocations_client.
    'access',
    'access_affinitygroup',
    'key',
    'access_news',
  ];

  /**
   * Builds a minimal news form array for the gating helper to act on.
   *
   * @return array<string, mixed>
   *   A form array with field_affiliation and
   *   field_choose_where_to_share_this present, the latter carrying the
   *   digest option plus one other option.
   */
  private function buildForm(): array {
    return [
      'field_affiliation' => [
        'widget' => [
          '#options' => ['access_nairr_office_hours' => 'ACCESS NAIRR Office Hours'],
        ],
      ],
      'field_choose_where_to_share_this' => [
        'widget' => [
          '#options' => [
            'in_the_access_support_bi_weekly_digest' => 'In the ACCESS Support bi-weekly digest',
            'on_the_announcements_page' => 'On the announcements page',
          ],
        ],
      ],
    ];
  }

  /**
   * Off the ACCESS Support domain, both the widget and option are hidden.
   */
  public function testOffSupportDomainHidesAffiliationAndDigest(): void {
    $this->stubActiveDomain('ccmnet_org');
    $form = $this->buildForm();
    _access_news_apply_domain_gating($form);

    $this->assertFalse($form['field_affiliation']['#access']);
    $this->assertArrayNotHasKey('in_the_access_support_bi_weekly_digest', $form['field_choose_where_to_share_this']['widget']['#options']);
    $this->assertArrayHasKey('on_the_announcements_page', $form['field_choose_where_to_share_this']['widget']['#options']);
  }

  /**
   * On the ACCESS Support domain, both the widget and option stay visible.
   */
  public function testSupportDomainShowsAffiliationAndDigest(): void {
    $this->stubActiveDomain('amp_cyberinfrastructure_org');
    $form = $this->buildForm();
    _access_news_apply_domain_gating($form);

    $this->assertNotSame(FALSE, $form['field_affiliation']['#access'] ?? NULL);
    $this->assertArrayHasKey('in_the_access_support_bi_weekly_digest', $form['field_choose_where_to_share_this']['widget']['#options']);
  }

  /**
   * With no negotiator at all, both are hidden (maximally protective).
   */
  public function testNoNegotiatorHidesBoth(): void {
    $form = $this->buildForm();
    _access_news_apply_domain_gating($form);

    $this->assertFalse($form['field_affiliation']['#access']);
    $this->assertArrayNotHasKey('in_the_access_support_bi_weekly_digest', $form['field_choose_where_to_share_this']['widget']['#options']);
  }

  /**
   * With a negotiator but no active domain, both are hidden.
   */
  public function testNoActiveDomainHidesBoth(): void {
    $this->stubActiveDomain(NULL);
    $form = $this->buildForm();
    _access_news_apply_domain_gating($form);

    $this->assertFalse($form['field_affiliation']['#access']);
    $this->assertArrayNotHasKey('in_the_access_support_bi_weekly_digest', $form['field_choose_where_to_share_this']['widget']['#options']);
  }

}
