<?php

declare(strict_types=1);

namespace Drupal\Tests\cssn\Kernel;

use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\cssn\Plugin\Block\PersonaBlock;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * The community persona block's "Edit Roles" link.
 *
 * CSSN is an ACCESS program, so the roles form must always open on ACCESS
 * Support. A relative link opened it on whichever domain showed the persona
 * (e.g. Open OnDemand), and the form's notification went out with that
 * domain's branding (D8-2953).
 *
 * @group cssn
 */
class PersonaBlockTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'file',
    'image',
    'taxonomy',
  ];

  /**
   * The persona owner.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $member;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user']);
    // EndUrl resolves the '<current>' route, which needs the router table.
    $this->container->get('router.builder')->rebuild();

    Vocabulary::create(['vid' => 'region', 'name' => 'Region'])->save();

    $string_fields = [
      'field_user_first_name',
      'field_user_last_name',
      'field_user_preferred_pronouns',
      'field_institution',
      'field_current_occupation',
      'field_askci_username',
      'field_github_username',
      'field_discourse_openondemand_org',
    ];
    foreach ($string_fields as $field_name) {
      $this->createUserField($field_name, 'string');
    }
    $this->createUserField('field_hide_community_profile', 'boolean');
    $this->createUserField('field_academic_status', 'list_string', [
      'allowed_values' => ['grad' => 'Graduate student'],
    ]);
    $this->createUserField('user_picture', 'image');
    // Only the target IDs are read, and the organization is left empty, so
    // any target type works; taxonomy_term avoids enabling node.
    foreach (['field_region', 'field_access_organization', 'field_user_badges', 'field_open_ondemand_badges'] as $field_name) {
      $this->createUserField($field_name, 'entity_reference', ['target_type' => 'taxonomy_term'], FieldStorageConfig::CARDINALITY_UNLIMITED);
    }

    $region = Term::create(['vid' => 'region', 'name' => 'ACCESS CSSN']);
    $region->save();

    // Take uid 1 so the persona owner is not the superuser.
    User::create(['name' => 'admin'])->save();
    $this->member = User::create([
      'name' => 'member',
      'status' => 1,
      'field_user_first_name' => 'Ada',
      'field_user_last_name' => 'Lovelace',
      'field_region' => [$region->id()],
    ]);
    $this->member->save();
  }

  /**
   * Tests that the member's own persona links Edit Roles to ACCESS Support.
   */
  public function testEditRolesLinkIsAbsoluteToAccessSupport(): void {
    $this->setCurrentUser($this->member);
    $this->setCurrentPath('cssn.page', '/community-persona', '/community-persona');

    $cssn_role = $this->buildBlock()['string']['#context']['cssn_role'];

    $this->assertIsArray($cssn_role);
    $url = $cssn_role['#url'];
    $this->assertInstanceOf(Url::class, $url);
    $this->assertTrue($url->isExternal());
    $this->assertStringStartsWith('https://support.access-ci.org/form/edit-your-cssn-roles', $url->toString());
    $this->assertSame('https://support.access-ci.org/form/edit-your-cssn-roles?destination=community-persona', $url->toString());
  }

  /**
   * Tests that a public persona viewed by someone else has no Edit Roles link.
   */
  public function testPublicPersonaHasNoEditRolesLink(): void {
    $viewer = User::create(['name' => 'viewer', 'status' => 1]);
    $viewer->save();
    $this->setCurrentUser($viewer);
    $uid = (string) $this->member->id();
    $this->setCurrentPath('cssn.public_page', '/community-persona/{uid}', '/community-persona/' . $uid, ['uid' => $uid]);

    $build = $this->buildBlock();

    $this->assertSame('', $build['string']['#context']['cssn_role']);
    $this->assertTrue($build['string']['#context']['public']);
  }

  /**
   * Creates a field on the user entity.
   *
   * @param string $field_name
   *   The field name.
   * @param string $type
   *   The field type.
   * @param array<string, mixed> $settings
   *   The field storage settings.
   * @param int $cardinality
   *   The field cardinality.
   */
  private function createUserField(string $field_name, string $type, array $settings = [], int $cardinality = 1): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'user',
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'user',
      'bundle' => 'user',
    ])->save();
  }

  /**
   * Makes '<current>' resolve to the given persona path.
   *
   * @param string $route_name
   *   The route name.
   * @param string $pattern
   *   The route path pattern.
   * @param string $path
   *   The concrete request path.
   * @param array<string, string> $parameters
   *   The raw route parameters.
   */
  private function setCurrentPath(string $route_name, string $pattern, string $path, array $parameters = []): void {
    $request = Request::create($path);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set('_route', $route_name);
    $request->attributes->set('_route_object', new Route($pattern));
    $request->attributes->set('_raw_variables', new ParameterBag($parameters));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Builds the persona block.
   *
   * @return array<string, mixed>
   *   The block render array.
   */
  private function buildBlock(): array {
    $block = PersonaBlock::create($this->container, [], 'cssn_block', ['provider' => 'cssn']);
    return $block->build();
  }

}
