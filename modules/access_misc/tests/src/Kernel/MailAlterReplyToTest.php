<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\domain\Entity\Domain;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the Reply-To and footer handling in access_misc_mail_alter().
 *
 * access_misc used to set Reply-To from domain_site_settings' per-domain
 * site_mail. That module went away in the domain 3.x upgrade (D8-2770), so
 * the lookup always came back empty, and if its config ever reappeared it
 * would override the Reply-To a sender chose on purpose (a reviewer's, on
 * Appverse decision mail). Reply-To is now left to the sender and to
 * access_misc_mailer_build().
 *
 * @group access_misc
 */
class MailAlterReplyToTest extends KernelTestBase {

  /**
   * The ACCESS Support domain id.
   */
  private const DOMAIN_ACCESS = 'amp_cyberinfrastructure_org';

  /**
   * {@inheritdoc}
   *
   * domain_site_settings ships no schema here: its module is gone, which is
   * exactly the leftover config this test plants.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'domain',
    // Container dependencies of access_misc's services, as in
    // ReviewRequestedDomainScopeTest.
    'access',
    'access_affinitygroup',
    'key',
    'access_misc',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $domain = Domain::create([
      'id' => self::DOMAIN_ACCESS,
      'hostname' => 'support.example.com',
      'name' => 'ACCESS Support',
      'scheme' => 'https',
      'status' => 1,
      'weight' => 0,
      'is_default' => TRUE,
    ]);
    $domain->save();
    \Drupal::service('domain.negotiator')->setActiveDomain($domain);
  }

  /**
   * Leftover domain_site_settings config does not set a Reply-To.
   */
  public function testLegacyMailGetsNoDomainSettingsReplyTo(): void {
    $this->config('domain_site_settings.domainconfigsettings')
      ->set(self::DOMAIN_ACCESS, ['site_mail' => 'stale@example.com'])
      ->save();
    $message = ['id' => 'user_password_reset', 'headers' => [], 'body' => []];

    access_misc_mail_alter($message);

    $this->assertArrayNotHasKey('Reply-To', $message['headers']);
  }

  /**
   * Ticketing mail is returned untouched.
   */
  public function testTicketingMailUntouched(): void {
    $message = [
      'id' => 'ticketing_ticketing',
      'from' => 'submitter@example.com',
      'headers' => ['From' => 'submitter@example.com'],
      'body' => ['Ticket body'],
    ];
    $before = $message;

    access_misc_mail_alter($message);

    $this->assertSame($before, $message);
  }

  /**
   * Moderation mail on ACCESS Support still gets the footer and From name.
   */
  public function testSupportModerationFooterStillAdded(): void {
    $message = [
      'id' => 'content_moderation_notifications_review',
      'from' => 'noreply@example.com',
      'headers' => ['Content-Type' => 'text/plain; charset=UTF-8'],
      'body' => ['A thing needs review.'],
    ];

    access_misc_mail_alter($message);

    $this->assertStringContainsString('https://support.access-ci.org/help-ticket', (string) end($message['body']));
    $this->assertSame('ACCESS Support <noreply@example.com>', $message['headers']['From']);
    $this->assertArrayNotHasKey('Reply-To', $message['headers']);
  }

}
