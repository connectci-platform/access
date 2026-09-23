<?php

declare(strict_types=1);

namespace Drupal\Tests\cssn\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\cssn\Plugin\Util\PersonaSitemapLookup;
use Drupal\domain\Entity\Domain;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;

/**
 * Personas published to a domain's XML sitemap.
 *
 * /community-persona/{uid} is a custom route, not an entity, so the sitemap
 * has to reproduce both the domain scoping (region terms carry the domain they
 * belong to) and the conditions the controller renders a public page under.
 *
 * @group cssn
 */
class PersonaSitemapLookupTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'taxonomy',
    'domain',
  ];

  /**
   * The lookup under test.
   *
   * @var \Drupal\cssn\Plugin\Util\PersonaSitemapLookup
   */
  protected $lookup;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['user']);

    Vocabulary::create(['vid' => 'region', 'name' => 'Region'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_region_connected_domain',
      'entity_type' => 'taxonomy_term',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_region_connected_domain',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'region',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_region',
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_region',
      'entity_type' => 'user',
      'bundle' => 'user',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_hide_community_profile',
      'entity_type' => 'user',
      'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_hide_community_profile',
      'entity_type' => 'user',
      'bundle' => 'user',
    ])->save();

    // Two domains with a directory of their own and one hub without.
    Domain::create(['id' => 'ccmnet_org', 'name' => 'CCMNet', 'hostname' => 'ccmnet.org'])->save();
    Domain::create(['id' => 'openondemand', 'name' => 'Open OnDemand', 'hostname' => 'ood.example.com'])->save();
    Domain::create(['id' => 'connectci', 'name' => 'Connect CI', 'hostname' => 'connectci.org'])->save();

    $this->lookup = new PersonaSitemapLookup(\Drupal::entityTypeManager());
  }

  /**
   * Creates a region term connected to a domain.
   */
  private function createRegion(string $name, ?string $connected_domain): Term {
    $term = Term::create([
      'vid' => 'region',
      'name' => $name,
      'field_region_connected_domain' => $connected_domain,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Creates a user account.
   */
  private function createPersona(string $name, array $values = []): User {
    $user = User::create($values + ['name' => $name, 'status' => 1]);
    $user->save();
    return $user;
  }

  /**
   * Only regions carrying the domain's machine name belong to the domain.
   */
  public function testRegionsAreResolvedFromTheDomainLabel(): void {
    $ccmnet = $this->createRegion('CCMNet', 'ccmnet');
    $ood = $this->createRegion('Open OnDemand', 'open-ondemand');
    $this->createRegion('At-Large', NULL);

    $this->assertSame([(string) $ccmnet->id()], $this->lookup->getRegionTermIds('ccmnet_org'));
    $this->assertSame([(string) $ood->id()], $this->lookup->getRegionTermIds('openondemand'));
  }

  /**
   * A domain with no region of its own publishes no personas.
   */
  public function testDomainWithoutRegionGetsNoPersonas(): void {
    $region = $this->createRegion('CCMNet', 'ccmnet');
    $this->createPersona('member', ['field_region' => $region->id()]);

    $this->assertSame([], $this->lookup->getRegionTermIds('connectci'));
    $this->assertSame([], $this->lookup->getPersonaUserIds([]));
  }

  /**
   * An unknown domain ID resolves to no regions.
   */
  public function testUnknownDomainGetsNoRegions(): void {
    $this->createRegion('CCMNet', 'ccmnet');

    $this->assertSame([], $this->lookup->getRegionTermIds('no_such_domain'));
  }

  /**
   * Hidden, blocked, region-less and other-region users stay out.
   */
  public function testOnlyPublishedPersonasOfTheRegionAreReturned(): void {
    $region = $this->createRegion('CCMNet', 'ccmnet');
    $other_region = $this->createRegion('Open OnDemand', 'open-ondemand');

    $visible = $this->createPersona('visible', ['field_region' => $region->id()]);
    $unset_flag = $this->createPersona('unset_flag', [
      'field_region' => $region->id(),
      'field_hide_community_profile' => NULL,
    ]);
    $explicitly_shown = $this->createPersona('explicitly_shown', [
      'field_region' => $region->id(),
      'field_hide_community_profile' => 0,
    ]);
    $this->createPersona('hidden', [
      'field_region' => $region->id(),
      'field_hide_community_profile' => 1,
    ]);
    $this->createPersona('blocked', [
      'field_region' => $region->id(),
      'status' => 0,
    ]);
    $this->createPersona('no_region');
    $this->createPersona('other_domain', ['field_region' => $other_region->id()]);

    $expected = array_map('strval', [
      $visible->id(),
      $unset_flag->id(),
      $explicitly_shown->id(),
    ]);
    sort($expected);

    $uids = $this->lookup->getPersonaUserIds($this->lookup->getRegionTermIds('ccmnet_org'));
    $this->assertSame($expected, $uids);
  }

  /**
   * A user in several regions is published to each of their domains.
   */
  public function testUserInMultipleRegionsBelongsToEachDomain(): void {
    $ccmnet = $this->createRegion('CCMNet', 'ccmnet');
    $ood = $this->createRegion('Open OnDemand', 'open-ondemand');
    $user = $this->createPersona('both', [
      'field_region' => [$ccmnet->id(), $ood->id()],
    ]);

    $this->assertSame(
      [(string) $user->id()],
      $this->lookup->getPersonaUserIds($this->lookup->getRegionTermIds('ccmnet_org'))
    );
    $this->assertSame(
      [(string) $user->id()],
      $this->lookup->getPersonaUserIds($this->lookup->getRegionTermIds('openondemand'))
    );
  }

}
