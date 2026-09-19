<?php

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\access_misc\FlagLinkBuilderDecorator;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\flag\Traits\FlagCreateTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;

/**
 * Tests FlagLinkBuilderDecorator against the real flag.link_builder service.
 *
 * Verifies the decorator restores flag 5.0.x behavior for anonymous users
 * denied a flag, where the link renders to an empty string with cache
 * contexts preserved. The decorator wraps the real (undecorated)
 * flag.link_builder service rather than a mock, so a future flag release
 * that reworks the anonymous branch or changes when #title is set fails this
 * test rather than silently reintroducing the regression.
 *
 * access_misc itself is not enabled here: its full .info.yml dependency
 * chain (access, access_events, operations_cider -> feeds/feeds_ex,
 * access_llm, access_affinitygroup, ...) is unrelated to this decorator and
 * KernelTestBase::enableModules() does not resolve dependencies, so booting
 * it would require standing up that entire unrelated graph. Instead
 * testServiceIsDecorated asserts the service wiring statically, and the
 * other tests instantiate the decorator directly around the real
 * flag.link_builder service.
 *
 * @group access_misc
 */
class FlagLinkBuilderDecoratorTest extends KernelTestBase {

  use FlagCreateTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'taxonomy',
    'flag',
  ];

  /**
   * The affinity_group flag.
   *
   * @var \Drupal\flag\FlagInterface
   */
  protected $flag;

  /**
   * A taxonomy term in the affinity_groups vocabulary.
   *
   * @var \Drupal\taxonomy\TermInterface
   */
  protected $term;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('flagging');
    $this->installSchema('flag', ['flag_counts']);
    $this->installConfig(['filter', 'flag', 'taxonomy', 'user', 'system']);

    // Create the affinity_groups vocabulary.
    Vocabulary::create([
      'vid' => 'affinity_groups',
      'name' => 'Affinity Groups',
    ])->save();

    // Create the affinity_group flag on taxonomy_term, matching the site's
    // real flags: reload link type, flag_short "Join".
    $this->flag = $this->createFlagFromArray([
      'id' => 'affinity_group',
      'label' => 'Affinity Group',
      'entity_type' => 'taxonomy_term',
      'bundles' => ['affinity_groups'],
      'flag_type' => 'entity:taxonomy_term',
      'link_type' => 'reload',
      'flag_short' => 'Join',
      'flag_message' => 'You have joined this affinity group.',
      'unflag_short' => 'Leave',
      'unflag_message' => 'You have left this affinity group.',
      'flagTypeConfig' => [
        'show_as_field' => TRUE,
        'show_on_form' => FALSE,
        'show_contextual_link' => FALSE,
      ],
    ]);

    // Create a taxonomy term in the affinity_groups vocabulary.
    $this->term = Term::create([
      'vid' => 'affinity_groups',
      'name' => 'Test Affinity Group',
    ]);
    $this->term->save();
  }

  /**
   * Tests the service is decorated.
   *
   * Catches a broken or missing `decorates:` entry in
   * access_misc.services.yml, or a missing `drupal:flag` dependency in
   * access_misc.info.yml. Reads the module's own files rather than booting
   * the container (see class docblock for why).
   */
  public function testServiceIsDecorated(): void {
    $module_path = \Drupal::service('extension.list.module')->getPath('access_misc');

    $info = Yaml::decode(file_get_contents($module_path . '/access_misc.info.yml'));
    $this->assertContains('drupal:flag', $info['dependencies'] ?? []);

    $services = Yaml::decode(file_get_contents($module_path . '/access_misc.services.yml'));
    $decorator_service = $services['services']['access_misc.flag_link_builder'] ?? NULL;
    $this->assertNotNull($decorator_service, 'access_misc.flag_link_builder service is defined.');
    $this->assertSame('flag.link_builder', $decorator_service['decorates'] ?? NULL);
    $this->assertSame(FlagLinkBuilderDecorator::class, $decorator_service['class'] ?? NULL);
  }

  /**
   * Tests an anonymous user denied the flag gets an empty render result.
   *
   * Anonymous holds no flag permission on this site, so this exercises the
   * exact branch that regressed under flag 5.1.0.
   */
  public function testAnonymousDeniedRendersEmpty(): void {
    $this->setCurrentUser(new AnonymousUserSession());

    $build = $this->decoratedLinkBuilder()
      ->build('taxonomy_term', $this->term->id(), 'affinity_group');

    $this->assertIsArray($build);
    $this->assertArrayNotHasKey('#title', $build);
    $this->assertArrayNotHasKey('#theme', $build);

    $rendered = \Drupal::service('renderer')->renderRoot($build);
    $this->assertSame('', (string) $rendered);
  }

  /**
   * Tests the anonymous-denied result still carries cacheable metadata.
   *
   * The Views field renders inside the view's render cache, so dropping the
   * access result's cache contexts could serve anonymous markup to logged-in
   * users.
   */
  public function testAnonymousDeniedKeepsCacheContexts(): void {
    $this->setCurrentUser(new AnonymousUserSession());

    $build = $this->decoratedLinkBuilder()
      ->build('taxonomy_term', $this->term->id(), 'affinity_group');

    $this->assertArrayHasKey('#cache', $build);
    $this->assertContains('user.permissions', $build['#cache']['contexts'] ?? []);
  }

  /**
   * Tests an authenticated user with permission still gets a real link.
   *
   * Confirms the decorator only intercepts the anonymous-denied case and
   * passes every other outcome through unchanged.
   */
  public function testAuthenticatedWithPermissionRendersLink(): void {
    $user = $this->createUser(['flag affinity_group', 'unflag affinity_group']);
    $this->setCurrentUser($user);

    $build = $this->decoratedLinkBuilder()
      ->build('taxonomy_term', $this->term->id(), 'affinity_group');

    $this->assertArrayHasKey('#title', $build);
    $this->assertSame('Join', $build['#title']['#markup']);
    $this->assertArrayHasKey('href', $build['#attributes']);

    $rendered = \Drupal::service('renderer')->renderRoot($build);
    $this->assertStringContainsString('Join', (string) $rendered);
  }

  /**
   * Builds a decorator wrapping the real, undecorated flag.link_builder.
   *
   * Access_misc is not enabled in this test (see class docblock), so the
   * container's flag.link_builder is flag's own FlagLinkBuilder. Wrapping it
   * directly exercises the decorator against real flag module behavior.
   */
  protected function decoratedLinkBuilder(): FlagLinkBuilderDecorator {
    return new FlagLinkBuilderDecorator(
      \Drupal::service('flag.link_builder'),
      \Drupal::currentUser(),
    );
  }

}
