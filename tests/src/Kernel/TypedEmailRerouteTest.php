<?php

namespace Drupal\Tests\access\Kernel;

use Drupal\Tests\symfony_mailer\Kernel\SymfonyMailerKernelTestBase;

/**
 * Tests that symfony_mailer typed emails honor the reroute_email settings.
 *
 * Rerouting is done by the reroute_email_symfony_mailer submodule's email
 * adjuster on the global mailer policy. This guards the site's reliance on it.
 *
 * @group access
 */
class TypedEmailRerouteTest extends SymfonyMailerKernelTestBase {

  /**
   * Cc address used by the tests.
   */
  protected const CC = 'cc@example.com';

  /**
   * Bcc address used by the tests.
   */
  protected const BCC = 'bcc@example.com';

  /**
   * Rerouting address used by the tests.
   */
  protected const REROUTE = 'reroute@example.com';

  /**
   * {@inheritdoc}
   *
   * The access, access_llm, key and access_affinitygroup modules are needed
   * for the container of the access module to compile.
   *
   * @var string[]
   */
  protected static $modules = [
    'symfony_mailer',
    'symfony_mailer_test',
    'symfony_mailer_legacy_test',
    'system',
    'user',
    'filter',
    'reroute_email',
    'reroute_email_symfony_mailer',
    'access',
    'access_llm',
    'key',
    'access_affinitygroup',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['reroute_email', 'filter']);
    $this->config('filter.settings')->set('fallback_format', 'plain_text')->save();
    $this->config('reroute_email.settings')
      ->set('address', self::REROUTE)
      ->save();

    // Kernel tests do not run hook_install(), so add the adjuster to the
    // global policy the way reroute_email_symfony_mailer_install() does.
    $policy = $this->container->get('entity_type.manager')
      ->getStorage('mailer_policy')
      ->load('_');
    $configuration = $policy->getConfiguration();
    $configuration['reroute_email'] = ['use_global' => TRUE];
    $policy->setConfiguration($configuration)->save();
  }

  /**
   * Sets reroute_email configuration values.
   *
   * @param array<string, mixed> $values
   *   The values to set, keyed by setting name.
   */
  protected function setRerouteConfig(array $values): void {
    $config = $this->config('reroute_email.settings');
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

  /**
   * Sends a typed email with Cc and Bcc, and reads it back.
   *
   * @param string $to
   *   (optional) The To address.
   */
  protected function sendTyped(string $to = ''): void {
    $this->emailFactory->newTypedEmail('symfony_mailer', 'test', $to ?: $this->addressTo)
      ->setCc(self::CC)
      ->setBcc(self::BCC)
      ->send();
    $this->readMail();
    $this->assertNoError();
  }

  /**
   * Gets a header value from the sent email.
   *
   * @param string $name
   *   The header name.
   *
   * @return string|null
   *   The value, or NULL if the header is not set.
   */
  protected function header(string $name): ?string {
    $header = $this->email->getHeaders()->get($name);
    return $header ? $header->getBodyAsString() : NULL;
  }

  /**
   * Asserts that the email was not rerouted.
   */
  protected function assertNotRerouted(): void {
    $this->assertTo($this->addressTo);
    $this->assertCc(self::CC);
    $this->assertBcc(self::BCC);
    $this->assertNull($this->header('X-Rerouted-Original-To'));
    $this->assertStringNotContainsString('This email was rerouted', $this->email->getHtmlBody());
  }

  /**
   * Tests that nothing changes when rerouting is disabled.
   */
  public function testRerouteDisabled(): void {
    $this->setRerouteConfig(['enable' => FALSE]);
    $this->sendTyped();
    $this->assertNotRerouted();
  }

  /**
   * Tests rerouting of a typed email.
   */
  public function testRerouteEnabled(): void {
    $this->setRerouteConfig(['enable' => TRUE]);
    $this->sendTyped();

    $this->assertTo(self::REROUTE);
    $this->assertCount(1, $this->email->getTo());
    $this->assertSame([], $this->email->getCc());
    $this->assertSame([], $this->email->getBcc());
    $this->assertSame($this->addressTo, $this->header('X-Rerouted-Original-To'));
    $this->assertSame(self::CC, $this->header('X-Rerouted-Original-Cc'));
    $this->assertSame(self::BCC, $this->header('X-Rerouted-Original-Bcc'));
    $this->assertSame('symfony_mailer_test', $this->header('X-Rerouted-Mail-Key'));
    $this->assertNotNull($this->header('X-Rerouted-Website'));

    $body = $this->email->getHtmlBody();
    $this->assertSame(1, substr_count($body, 'This email was rerouted.'));
    $this->assertSame(1, substr_count($body, 'Mail key: symfony_mailer_test'));
    $this->assertSame(1, substr_count($body, 'Originally to: ' . $this->addressTo));
    $this->assertSame(1, substr_count($body, 'Originally cc: ' . self::CC));
    $this->assertSame(1, substr_count($body, 'Originally bcc: ' . self::BCC));
  }

  /**
   * Tests that user-controlled addresses are escaped in the body notice.
   */
  public function testNoticeIsEscaped(): void {
    $this->setRerouteConfig(['enable' => TRUE]);
    $this->sendTyped('a&b@example.com');

    $body = $this->email->getHtmlBody();
    $this->assertStringContainsString('Originally to: a&amp;b@example.com', $body);
    $this->assertStringNotContainsString('Originally to: a&b@example.com', $body);
  }

  /**
   * Tests rerouting with the description disabled.
   */
  public function testRerouteWithoutDescription(): void {
    $this->setRerouteConfig(['enable' => TRUE, 'description' => FALSE]);
    $this->sendTyped();

    $this->assertTo(self::REROUTE);
    $this->assertSame($this->addressTo, $this->header('X-Rerouted-Original-To'));
    $this->assertStringNotContainsString('This email was rerouted', $this->email->getHtmlBody());
  }

  /**
   * Tests that emails to allowlisted addresses are not rerouted.
   */
  public function testAllowedAddressNotRerouted(): void {
    $this->setRerouteConfig([
      'enable' => TRUE,
      'allowed' => implode("\n", [$this->addressTo, self::CC, self::BCC]),
    ]);
    $this->sendTyped();
    $this->assertNotRerouted();
  }

  /**
   * Tests that emails matching an allowlist pattern are not rerouted.
   */
  public function testAllowedPatternNotRerouted(): void {
    $this->setRerouteConfig(['enable' => TRUE, 'allowed' => '*@example.com']);
    $this->sendTyped();
    $this->assertNotRerouted();
  }

  /**
   * Tests that emails not matching an allowlist pattern are rerouted.
   */
  public function testNonMatchingPatternRerouted(): void {
    $this->setRerouteConfig(['enable' => TRUE, 'allowed' => '*@example.org']);
    $this->sendTyped();
    $this->assertTo(self::REROUTE);
  }

  /**
   * Tests that skipped mail keys are not rerouted.
   */
  public function testSkippedMailKeyNotRerouted(): void {
    $this->setRerouteConfig(['enable' => TRUE, 'mailkeys_skip' => 'symfony_mailer_test']);
    $this->sendTyped();
    $this->assertNotRerouted();
  }

  /**
   * Tests that an empty rerouting address aborts sending.
   */
  public function testEmptyAddressSkipsSend(): void {
    $this->setRerouteConfig(['enable' => TRUE, 'address' => '']);
    $this->emailFactory->newTypedEmail('symfony_mailer', 'test', $this->addressTo)
      ->setCc(self::CC)
      ->send();
    $this->init();
    $this->assertCount(0, $this->emails);
  }

  /**
   * Tests that a legacy email is rerouted and announced exactly once.
   */
  public function testLegacyEmailReroutedOnce(): void {
    $this->setRerouteConfig(['enable' => TRUE]);
    $this->container->get('plugin.manager.mail')
      ->mail('symfony_mailer_legacy_test', 'legacy_test', $this->addressTo, 'en');
    $this->readMail();

    $this->assertTo(self::REROUTE);
    $this->assertSame(1, substr_count($this->email->getHtmlBody(), 'This email was rerouted.'));
  }

}
