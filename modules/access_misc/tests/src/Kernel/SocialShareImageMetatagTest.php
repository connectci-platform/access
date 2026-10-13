<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\domain\DomainInterface;
use Drupal\domain\DomainNegotiatorInterface;
use Drupal\image\Entity\ImageStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the social share (Open Graph) image metatag handling.
 *
 * D8-2897: pages without an og:image fall back to the active domain's share
 * image (or logo). access_misc_metatags_attachments_alter() fills empty
 * og_image values, adds og_image when missing, and emits 1200x630 dimensions
 * only for the share image and og_share derivatives. It never emits
 * og:image:url.
 *
 * @group access_misc
 */
class SocialShareImageMetatagTest extends KernelTestBase {

  /**
   * The domain id used for most tests.
   */
  private const DOMAIN_ID = 'amp_cyberinfrastructure_org';

  /**
   * The share image path configured for the test domain.
   */
  private const SHARE_PATH = '/themes/contrib/asp-theme/images/logos/access_support-logo-share.png';

  /**
   * The logo path configured for the test domain.
   */
  private const LOGO_PATH = '/themes/contrib/asp-theme/images/logos/access_support-logo.svg';

  /**
   * The scheme and host the stubbed request uses.
   */
  private const BASE = 'https://example.com';

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
    'domain',
    'file',
    'image',
    // Container dependencies, not assertions of this test: access_misc's
    // services.yml wires event subscribers against these. Same set the other
    // access_misc kernel tests carry.
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
    $this->installConfig(['system']);

    $request = Request::create(self::BASE . '/node/1');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $this->setActiveDomain(self::DOMAIN_ID);
    $this->setLogoConfig(self::SHARE_PATH, self::LOGO_PATH);
  }

  /**
   * Replaces the domain negotiator with a stub returning the given domain.
   */
  protected function setActiveDomain(?string $id): void {
    $negotiator = $this->createMock(DomainNegotiatorInterface::class);
    if ($id === NULL) {
      $domain = NULL;
    }
    else {
      $domain = $this->createMock(DomainInterface::class);
      $domain->method('id')->willReturn($id);
    }
    $negotiator->method('getActiveDomain')->willReturn($domain);
    $this->container->set('domain.negotiator', $negotiator);
  }

  /**
   * Sets the share image and logo config for the test domain.
   */
  protected function setLogoConfig(string $share, string $logo): void {
    $this->config('access_misc.domain_logos')
      ->set('share_images.' . self::DOMAIN_ID, $share)
      ->set('logos.' . self::DOMAIN_ID, $logo)
      ->save();
  }

  /**
   * Builds an attachments array holding a description and given og items.
   *
   * @param array $og_items
   *   Map of metatag key => property/content pair as [property, content].
   *
   * @return array
   *   The attachments array.
   */
  protected function buildAttachments(array $og_items = []): array {
    $html_head = [
      [
        ['#tag' => 'meta', '#attributes' => ['name' => 'description', 'content' => 'Hello']],
        'description',
      ],
    ];
    foreach ($og_items as $key => [$property, $content]) {
      $html_head[] = [
        ['#tag' => 'meta', '#attributes' => ['property' => $property, 'content' => $content]],
        $key,
      ];
    }
    return ['#attached' => ['html_head' => $html_head]];
  }

  /**
   * Runs the alter hook and returns items keyed by metatag key.
   *
   * @return array
   *   Map of key => meta attributes.
   */
  protected function alter(array $attachments, ?array &$raw = NULL): array {
    access_misc_metatags_attachments_alter($attachments);
    $raw = $attachments['#attached']['html_head'];
    $by_key = [];
    foreach ($raw as $item) {
      $by_key[$item[1]] = $item[0]['#attributes'];
    }
    return $by_key;
  }

  /**
   * Asserts no item uses the og:image:url property.
   */
  protected function assertNoOgImageUrl(array $raw): void {
    foreach ($raw as $item) {
      $this->assertNotSame('og:image:url', $item[0]['#attributes']['property'] ?? NULL);
    }
  }

  /**
   * Tests the share image is added, with dimensions, when no image tag exists.
   */
  public function testShareImageFallbackWhenNoImageTag(): void {
    $items = $this->alter($this->buildAttachments(), $raw);
    $this->assertSame('og:image', $items['og_image']['property']);
    $this->assertSame(self::BASE . self::SHARE_PATH, $items['og_image']['content']);
    $this->assertStringEndsWith('/access_support-logo-share.png', $items['og_image']['content']);
    $this->assertSame('og:image:width', $items['og_image_width']['property']);
    $this->assertEquals(1200, $items['og_image_width']['content']);
    $this->assertSame('og:image:height', $items['og_image_height']['property']);
    $this->assertEquals(630, $items['og_image_height']['content']);
    $this->assertNoOgImageUrl($raw);
  }

  /**
   * Tests an empty og_image_0 is filled with the share image.
   */
  public function testEmptyOgImageFilledWithShareImage(): void {
    $items = $this->alter($this->buildAttachments(['og_image_0' => ['og:image', '']]), $raw);
    $this->assertSame(self::BASE . self::SHARE_PATH, $items['og_image_0']['content']);
    $this->assertArrayNotHasKey('og_image', $items);
    $this->assertArrayHasKey('og_image_width', $items);
    $this->assertArrayHasKey('og_image_height', $items);
    $this->assertNoOgImageUrl($raw);
  }

  /**
   * Tests an og_share derivative URL is untouched and gets dimensions.
   */
  public function testOgShareDerivativeKeepsUrlAndGetsDimensions(): void {
    $url = 'https://example.com/sites/default/files/styles/og_share/public/x.jpg?itok=a';
    $items = $this->alter($this->buildAttachments(['og_image_0' => ['og:image', $url]]), $raw);
    $this->assertSame($url, $items['og_image_0']['content']);
    $this->assertArrayNotHasKey('og_image', $items);
    $this->assertEquals(1200, $items['og_image_width']['content']);
    $this->assertEquals(630, $items['og_image_height']['content']);
    $this->assertNoOgImageUrl($raw);
  }

  /**
   * Tests an unrelated image keeps its URL and gets no dimensions.
   */
  public function testOtherImageGetsNoDimensions(): void {
    $url = '/sites/default/files/foo.png';
    $items = $this->alter($this->buildAttachments(['og_image' => ['og:image', $url]]), $raw);
    $this->assertSame($url, $items['og_image']['content']);
    $this->assertArrayNotHasKey('og_image_width', $items);
    $this->assertArrayNotHasKey('og_image_height', $items);
    $this->assertNoOgImageUrl($raw);
  }

  /**
   * Tests an empty share image entry falls back to the logo.
   *
   * The logo is whatever size that file happens to be, so no dimensions are
   * claimed for it. Only a configured share image and og_share derivatives are
   * known to be 1200x630. D8-2900 adds the missing per-domain artwork.
   */
  public function testShareImageFallsBackToLogo(): void {
    $this->setLogoConfig('', self::LOGO_PATH);
    $items = $this->alter($this->buildAttachments(), $raw);
    $this->assertSame(self::BASE . self::LOGO_PATH, $items['og_image']['content']);
    $this->assertArrayNotHasKey('og_image_width', $items);
    $this->assertArrayNotHasKey('og_image_height', $items);
    $this->assertNoOgImageUrl($raw);
  }

  /**
   * Tests nothing is added when neither share image nor logo is configured.
   */
  public function testNoShareImageOrLogoAddsNothing(): void {
    $this->setLogoConfig('', '');
    $items = $this->alter($this->buildAttachments(), $raw);
    $this->assertArrayNotHasKey('og_image', $items);
    $this->assertArrayNotHasKey('og_image_width', $items);
    $this->assertArrayNotHasKey('og_image_height', $items);
    $this->assertNoOgImageUrl($raw);
  }

  /**
   * Tests the og_share image style is 1200x630 and converts to jpg.
   */
  public function testOgShareImageStyleDimensions(): void {
    $path = DRUPAL_ROOT . '/sites/default/config/default/image.style.og_share.yml';
    $this->assertFileExists($path);
    $data = Yaml::parseFile($path);
    ImageStyle::create($data)->save();

    $style = ImageStyle::load('og_share');
    $this->assertNotNull($style);
    $dimensions = ['width' => 300, 'height' => 300];
    $style->transformDimensions($dimensions, 'public://x.png');
    $this->assertSame(1200, (int) $dimensions['width']);
    $this->assertSame(630, (int) $dimensions['height']);
    $this->assertSame('jpg', $style->getDerivativeExtension('png'));
  }

}
