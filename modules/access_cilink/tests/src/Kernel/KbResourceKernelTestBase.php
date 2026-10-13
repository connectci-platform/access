<?php

namespace Drupal\Tests\access_cilink\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\domain\Entity\Domain;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\flag\Entity\Flag;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared fixture for the KB resource kernel tests.
 *
 * Installs a minimal "resource" webform, two domains with region terms mapped
 * to them, the upvote flag, and an affinity_group bundle.
 */
abstract class KbResourceKernelTestBase extends KernelTestBase {

  /**
   * Hostname of the support domain.
   */
  const SUPPORT_HOST = 'support.example.com';

  /**
   * Hostname of the other domain.
   */
  const OTHER_HOST = 'other.example.com';

  /**
   * {@inheritdoc}
   *
   * @var array<string>
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'taxonomy',
    'webform',
    'flag',
    'domain',
    'page_cache',
    'access_cilink',
    'access_cilink_test',
  ];

  /**
   * Region term for the support domain.
   */
  protected Term $supportRegion;

  /**
   * Region term for the other domain.
   */
  protected Term $otherRegion;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    // Let the internal page cache work under the CLI SAPI.
    $container->getDefinition('page_cache_request_policy')
      ->setClass(NonCliRequestPolicy::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Anonymous and authenticated users can view content, as in production.
    // The roles must exist before anything looks up permissions: the access
    // policy cache would otherwise keep an empty result for a missing role.
    foreach (['anonymous', 'authenticated'] as $rid) {
      Role::create(['id' => $rid, 'label' => $rid])->grantPermission('access content')->save();
    }
    Role::create(['id' => 'administrator', 'label' => 'Administrator'])->save();
    Role::create(['id' => 'kb_pm', 'label' => 'KB PM'])->save();
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('webform_submission');
    $this->installEntitySchema('flagging');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('webform', ['webform']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installConfig(['system', 'node', 'filter', 'webform', 'domain']);

    // User 1 is the superuser; burn it so test users are ordinary.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    foreach (['support' => self::SUPPORT_HOST, 'other' => self::OTHER_HOST] as $id => $host) {
      Domain::create([
        'id' => $id,
        'hostname' => $host,
        'name' => ucfirst($id),
        'scheme' => 'https',
        'status' => 1,
        'weight' => $id === 'support' ? 1 : 2,
      ])->save();
    }

    // Vocabularies.
    foreach (['region', 'tags', 'skill_level'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_region_connected_domain',
      'entity_type' => 'taxonomy_term',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_region_connected_domain',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'region',
      'label' => 'Connected domain',
    ])->save();
    // The domain class is Html::getClass(domain label).
    $this->supportRegion = $this->createTerm('region', 'Support region', 'support');
    $this->otherRegion = $this->createTerm('region', 'Other region', 'other');

    // The resource webform, with the elements the repository reads.
    Webform::create([
      'id' => 'resource',
      'title' => 'Knowledge Base Resources',
      'elements' => <<<YAML
approved:
  '#type': checkbox
title:
  '#type': textfield
terms:
  '#type': textfield
category:
  '#type': select
  '#options':
    video_link: Video
    code: Code
skill_level:
  '#type': webform_term_checkboxes
  '#vocabulary': skill_level
description_html:
  '#type': text_format
link_to_resource:
  '#type': webform_link
  '#multiple': true
tags:
  '#type': webform_term_checkboxes
  '#vocabulary': tags
domain:
  '#type': webform_term_select
  '#multiple': true
  '#vocabulary': region
resource_allowed_on_affinity_group:
  '#type': checkbox
private:
  '#type': checkbox
YAML,
    ])->save();

    Flag::create([
      'id' => 'upvote',
      'label' => 'Upvote',
      'bundles' => ['resource'],
      'entity_type' => 'webform_submission',
      'flag_type' => 'entity:webform_submission',
      'link_type' => 'reload',
      'flagTypeConfig' => ['show_in_links' => []],
      'linkTypeConfig' => [],
    ])->save();
  }

  /**
   * Creates a term, optionally mapped to a domain class.
   */
  protected function createTerm(string $vid, string $name, ?string $domain_class = NULL): Term {
    $values = ['vid' => $vid, 'name' => $name];
    if ($domain_class !== NULL) {
      // The domain label is e.g. "Support"; Html::getClass() lowercases it.
      $values['field_region_connected_domain'] = $domain_class;
    }
    $term = Term::create($values);
    $term->save();
    return $term;
  }

  /**
   * Creates a resource submission.
   *
   * @param array<string, mixed> $data
   *   Submission data overriding the defaults. Set a key to NULL to omit it.
   * @param int $uid
   *   The owner.
   */
  protected function createResource(array $data = [], int $uid = 0): WebformSubmission {
    $data += [
      'approved' => 1,
      'private' => 0,
      'title' => 'A resource',
      'terms' => '',
      'category' => 'video_link',
      'domain' => [$this->supportRegion->id()],
    ];
    $data = array_filter($data, fn($value) => $value !== NULL);
    $submission = WebformSubmission::create([
      'webform_id' => 'resource',
      'uid' => $uid,
      'data' => $data,
    ]);
    $submission->save();
    return $submission;
  }

  /**
   * Creates a user with roles.
   *
   * @param string[] $roles
   *   Role IDs.
   */
  protected function createUser(array $roles = []): User {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'status' => 1,
      'roles' => $roles,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Dispatches a GET request through the full HTTP kernel.
   *
   * @param string $path
   *   The path.
   * @param string $host
   *   The request host.
   * @param \Drupal\user\Entity\User|null $user
   *   The user, or NULL for anonymous.
   */
  protected function get(string $path, string $host = self::SUPPORT_HOST, ?User $user = NULL): Response {
    $request = Request::create('https://' . $host . $path);
    // Production negotiates the domain once per PHP request. This test process
    // serves several, so clear the negotiator's per-process state.
    $negotiator = $this->container->get('domain.negotiator');
    $negotiated = new \ReflectionProperty($negotiator, 'negotiated');
    $negotiated->setValue($negotiator, FALSE);
    // The page cache middleware also memoizes its cache ID per PHP request.
    $middleware = $this->container->get('http_middleware.page_cache');
    $cid = new \ReflectionProperty($middleware, 'cid');
    $cid->setValue($middleware, NULL);
    if ($user) {
      // Authenticated production requests carry a session cookie, which keeps
      // them out of the page cache. Model that.
      $session_name = $this->container->get('session_configuration')->getOptions($request)['name'];
      $request->cookies->set($session_name, 'test-session');
    }
    $this->container->get('current_user')->setAccount($user ?? new AnonymousUserSession());
    return $this->container->get('http_kernel')->handle($request);
  }

}
