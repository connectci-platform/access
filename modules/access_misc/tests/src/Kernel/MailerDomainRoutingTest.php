<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\domain\Entity\Domain;
use Drupal\KernelTests\KernelTestBase;
use Drupal\symfony_mailer\Entity\MailerTransport;
use Drupal\symfony_mailer\EmailInterface;

/**
 * Tests the From/Reply-To and transport chosen by access_misc_mailer_build().
 *
 * D8-2908: Appverse mail (symfony_mailer type ood_software, from
 * ood_software's hook_mail) is mostly sent from cron. Cron has no request, so
 * the active domain is the default ACCESS domain and the mail went out as
 * "ACCESS Support" instead of Open OnDemand. Email types listed in
 * the email type map in access_misc_mailer_build() must use their own
 * domain's identity whatever the active domain is.
 *
 * @group access_misc
 * @group legacy
 */
class MailerDomainRoutingTest extends KernelTestBase {

  private const OOD_DSN = 'smtp://ood-user:secret@smtp.mailgun.example:587';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'domain',
    'mailer_transport',
    'symfony_mailer',
    // Container dependencies, same set as ReviewRequestedDomainScopeTest.
    'access',
    'access_affinitygroup',
    'key',
    'access_misc',
  ];

  /**
   * The IS_DDEV_PROJECT value to restore after the test.
   *
   * @var string|false
   */
  private string|false $originalDdevEnv;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // symfony_mailer 1.6.x calls the deprecated ModuleHandler::getName() while
    // building its email builder definitions. That is contrib code, so this
    // class is in @group legacy rather than pinning an exact count that a
    // contrib update would break.
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['domain', 'symfony_mailer']);

    foreach (['amp_cyberinfrastructure_org' => 'amp', 'openondemand_cyberinfrastructure_org' => 'ood'] as $id => $host) {
      Domain::create([
        'id' => $id,
        'hostname' => "$host.example.com",
        'name' => $id,
        'scheme' => 'https',
        'status' => 1,
      ])->save();
    }
    MailerTransport::create([
      'id' => 'smtp_ood',
      'label' => 'OOD',
      'plugin' => 'dsn',
      'configuration' => ['dsn' => self::OOD_DSN],
    ])->save();

    // The hook skips the transport under ddev; the transport assertions need
    // it to run.
    $this->originalDdevEnv = getenv('IS_DDEV_PROJECT');
    putenv('IS_DDEV_PROJECT');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->originalDdevEnv !== FALSE) {
      putenv('IS_DDEV_PROJECT=' . $this->originalDdevEnv);
    }
    parent::tearDown();
  }

  /**
   * Builds an email through the real symfony_mailer build phase.
   *
   * EmailFactory::newTypedEmail() applies policy, then Email::process() runs
   * every build-phase callback at its real weight, including
   * hook_mailer_build(). A change to the type string or the hook weight is
   * therefore caught here. ood_software itself is not enabled in this suite,
   * so a pre-set Reply-To stands in for what its mail builder sets.
   */
  private function build(string $type, string $sub_type, string $active_domain, ?string $reply_to = NULL): EmailInterface {
    $domain = Domain::load($active_domain);
    \Drupal::service('domain.negotiator')->setActiveDomain($domain);
    \Drupal::service('domain.negotiation_context')->setNegotiated(TRUE);
    $email = \Drupal::service('email_factory')->newTypedEmail($type, $sub_type);
    if ($reply_to) {
      $email->setReplyTo($reply_to);
    }
    $email->process();
    return $email;
  }

  /**
   * Appverse mail from the default domain still goes out as Open OnDemand.
   */
  public function testOodSoftwareMailUsesOodIdentityUnderDefaultDomain(): void {
    $email = $this->build('ood_software', 'review_update', 'amp_cyberinfrastructure_org');

    $this->assertSame('info@mg.openondemand.org', $email->getFrom()[0]->getEmail());
    $this->assertSame('Open OnDemand', $email->getFrom()[0]->getDisplayName());
    $this->assertSame('info@openondemand.org', $email->getReplyTo()[0]->getEmail());
    $this->assertSame(self::OOD_DSN, $email->getTransportDsn());
  }

  /**
   * An explicit Reply-To (the reviewer, for review mail) is not overwritten.
   */
  public function testExplicitReplyToIsKept(): void {
    $email = $this->build('ood_software', 'review_decision', 'amp_cyberinfrastructure_org', 'reviewer@example.com');

    $this->assertSame('reviewer@example.com', $email->getReplyTo()[0]->getEmail());
    $this->assertSame('info@mg.openondemand.org', $email->getFrom()[0]->getEmail());
    $this->assertSame(self::OOD_DSN, $email->getTransportDsn());
  }

  /**
   * Mail of other types keeps the active domain's identity.
   */
  public function testOtherMailUnderDefaultDomainIsUnchanged(): void {
    $email = $this->build('unrelated_type', 'notice', 'amp_cyberinfrastructure_org');

    $this->assertSame('noreply@mg.support.access-ci.org', $email->getFrom()[0]->getEmail());
    $this->assertSame('ACCESS Support', $email->getFrom()[0]->getDisplayName());
    $this->assertSame([], $email->getReplyTo());
    $this->assertNotSame(self::OOD_DSN, $email->getTransportDsn());
  }

  /**
   * Non-ood mail on the OnDemand domain still uses the OnDemand identity.
   */
  public function testOtherMailUnderOodDomainUsesOodIdentity(): void {
    $email = $this->build('unrelated_type', 'notice', 'openondemand_cyberinfrastructure_org');

    $this->assertSame('info@mg.openondemand.org', $email->getFrom()[0]->getEmail());
    $this->assertSame(self::OOD_DSN, $email->getTransportDsn());
  }

}
