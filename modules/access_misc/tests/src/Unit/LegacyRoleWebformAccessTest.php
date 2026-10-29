<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that legacy roles hold no access in the exported configuration.
 *
 * D8-1555: the legacy roles sc, lt and ra stay but get no access at all, the
 * masquerade role is deleted, and pascience_manager keeps its access to the
 * project webform's approval checkboxes. This reads the exported config in
 * the site's sync directory, so it needs no Drupal install; it is a unit test
 * because nothing is bootstrapped.
 *
 * @group access_misc
 */
class LegacyRoleWebformAccessTest extends UnitTestCase {

  /**
   * Roles that must have no access to the webforms.
   */
  const REMOVED_ROLES = ['sc', 'lt', 'ra', 'masquerade'];

  /**
   * Element-level access keys that list roles.
   */
  const ELEMENT_ROLE_KEYS = [
    '#access_create_roles',
    '#access_update_roles',
    '#access_view_roles',
  ];

  /**
   * Finds the config sync directory relative to this file.
   */
  protected function getSyncDir(): string {
    $dir = __DIR__;
    while (($parent = dirname($dir)) !== $dir) {
      $candidate = $dir . '/sites/default/config/default';
      if (is_dir($candidate)) {
        return $candidate;
      }
      $dir = $parent;
    }
    $this->markTestSkipped('Config sync directory sites/default/config/default not found; the access sub-repo may be checked out standalone.');
  }

  /**
   * Loads an exported config object.
   */
  protected function loadConfig(string $name): array {
    $file = $this->getSyncDir() . '/' . $name . '.yml';
    $this->assertFileExists($file, "Config $name is missing from the sync directory.");
    $data = Yaml::parse(file_get_contents($file));
    $this->assertIsArray($data, "Config $name did not parse to an array.");
    return $data;
  }

  /**
   * Loads a webform config and its parsed elements.
   *
   * @return array
   *   A two item array: config data and parsed elements.
   */
  protected function loadWebform(string $id): array {
    $config = $this->loadConfig('webform.webform.' . $id);
    $elements = $config['elements'] ?? '';
    $parsed = is_string($elements) ? Yaml::parse($elements) : $elements;
    return [$config, is_array($parsed) ? $parsed : []];
  }

  /**
   * Flattens nested webform elements into a key => element map.
   */
  protected function flattenElements(array $elements, array &$flat = []): array {
    foreach ($elements as $key => $element) {
      if (is_string($key) && str_starts_with($key, '#')) {
        continue;
      }
      if (is_array($element)) {
        $flat[$key] = $element;
        $this->flattenElements($element, $flat);
      }
    }
    return $flat;
  }

  /**
   * Provides webform ids.
   */
  public static function webformProvider(): array {
    return [
      'resource' => ['resource'],
      'issue' => ['issue'],
      'project' => ['project'],
      'request_tag' => ['request_tag'],
    ];
  }

  /**
   * Webform access rules grant nothing to the removed roles.
   *
   * @dataProvider webformProvider
   */
  public function testWebformAccessHasNoRemovedRoles(string $id): void {
    [$config] = $this->loadWebform($id);
    foreach ($config['access'] ?? [] as $operation => $rule) {
      $roles = $rule['roles'] ?? [];
      foreach (self::REMOVED_ROLES as $role) {
        $this->assertNotContains($role, $roles, "Webform $id access operation $operation still grants role $role.");
      }
    }
  }

  /**
   * Element access rules grant nothing to the removed roles.
   *
   * @dataProvider webformProvider
   */
  public function testElementAccessHasNoRemovedRoles(string $id): void {
    [, $elements] = $this->loadWebform($id);
    $flat = $this->flattenElements($elements);
    foreach ($flat as $key => $element) {
      foreach (self::ELEMENT_ROLE_KEYS as $property) {
        $roles = $element[$property] ?? [];
        foreach (self::REMOVED_ROLES as $role) {
          $this->assertNotContains($role, (array) $roles, "Webform $id element $key $property still grants role $role.");
        }
      }
    }
  }

  /**
   * The pascience_manager role can update and see approved milestones.
   */
  public function testProjectGrantsPascienceManager(): void {
    [$config, $elements] = $this->loadWebform('project');
    $this->assertContains('pascience_manager', $config['access']['update_any']['roles'] ?? [], 'Webform project access update_any lacks pascience_manager.');
    $flat = $this->flattenElements($elements);
    foreach (['approved_milestones', 'approved'] as $key) {
      $this->assertArrayHasKey($key, $flat, "Webform project has no element $key.");
      foreach (self::ELEMENT_ROLE_KEYS as $property) {
        $this->assertContains('pascience_manager', (array) ($flat[$key][$property] ?? []), "Webform project element $key $property lacks pascience_manager.");
      }
    }
  }

  /**
   * Provides legacy roles that remain but hold no permissions.
   */
  public static function legacyRoleProvider(): array {
    return ['sc' => ['sc'], 'lt' => ['lt'], 'ra' => ['ra']];
  }

  /**
   * Legacy roles stay with an empty permission set.
   *
   * @dataProvider legacyRoleProvider
   */
  public function testLegacyRoleHasNoPermissions(string $role): void {
    $config = $this->loadConfig('user.role.' . $role);
    $this->assertEmpty($config['permissions'] ?? [], "Role $role still has permissions: " . implode(', ', (array) ($config['permissions'] ?? [])));
  }

  /**
   * The masquerade role is deleted.
   */
  public function testMasqueradeRoleIsDeleted(): void {
    $this->assertFileDoesNotExist($this->getSyncDir() . '/user.role.masquerade.yml', 'Role masquerade config should be deleted.');
  }

}
