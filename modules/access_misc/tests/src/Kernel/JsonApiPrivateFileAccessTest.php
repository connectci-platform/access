<?php

declare(strict_types=1);

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests that non-public files are not exposed over JSON:API.
 *
 * The file--file resource is allowlisted so media logos resolve. Core's file
 * "view" access for a private file follows the referencing entities, so a
 * user's private resume is viewable by anyone with "access user profiles".
 * JsonApiPrivateFileAccess forbids "view" of non-public files on JSON:API
 * routes only.
 *
 * @coversDefaultClass \Drupal\access_misc\Access\JsonApiPrivateFileAccess
 * @group access_misc
 */
class JsonApiPrivateFileAccessTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'field',
    'serialization',
    'jsonapi',
    // Container dependencies of access_misc's services, as in
    // SiteLogoTokenTest.
    'dblog',
    'domain',
    'language',
    'domain_config',
    'access',
    'access_affinitygroup',
    'key',
    'access_misc',
  ];

  /**
   * A public file.
   */
  protected FileInterface $publicFile;

  /**
   * A private file that core would let anonymous view.
   */
  protected FileInterface $privateFile;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem() {
    // The private stream wrapper is only registered when the path is set
    // before the container is built.
    mkdir($this->siteDirectory, 0775);
    mkdir($this->siteDirectory . '/files', 0775);
    mkdir($this->siteDirectory . '/private/resume', 0775, TRUE);
    $this->setSetting('file_public_path', $this->siteDirectory . '/files');
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['system', 'user', 'field']);

    // A private file field on users, like field_cv_resume.
    FieldStorageConfig::create([
      'field_name' => 'field_cv_resume',
      'entity_type' => 'user',
      'type' => 'file',
      'settings' => ['uri_scheme' => 'private'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_cv_resume',
      'entity_type' => 'user',
      'bundle' => 'user',
    ])->save();

    // Anonymous may view user profiles, hence the private files they
    // reference, and content (needed to view public files and for JSON:API).
    Role::load(RoleInterface::ANONYMOUS_ID)
      ->grantPermission('access user profiles')
      ->grantPermission('access content')
      ->save();

    file_put_contents('public://logo.png', 'logo');
    $this->publicFile = File::create(['uri' => 'public://logo.png', 'uid' => 1]);
    $this->publicFile->setPermanent();
    $this->publicFile->save();

    file_put_contents('private://resume/secret.pdf', 'secret');
    $this->privateFile = File::create([
      'uri' => 'private://resume/secret.pdf',
      'uid' => 1,
    ]);
    $this->privateFile->setPermanent();
    $this->privateFile->save();

    User::create([
      'name' => 'applicant',
      'mail' => 'applicant@example.com',
      'status' => 1,
      'field_cv_resume' => ['target_id' => $this->privateFile->id()],
    ])->save();

    $this->container->get('router.builder')->rebuild();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
  }

  /**
   * Sends an anonymous JSON:API GET through the HTTP kernel.
   */
  protected function get(string $path): Response {
    $request = Request::create($path, 'GET', [], [], [], [
      'HTTP_ACCEPT' => 'application/vnd.api+json',
    ]);
    $response = $this->container->get('http_kernel')->handle($request);
    // Leave the request stack as it was for subsequent direct checks.
    $this->container->get('request_stack')->pop();
    return $response;
  }

  /**
   * The private file really is viewable by core's rules outside JSON:API.
   */
  public function testPrivateFileIsCoreViewable(): void {
    $anonymous = new AnonymousUserSession();
    $this->assertTrue($this->privateFile->access('view', $anonymous));
    $this->assertTrue($this->publicFile->access('view', $anonymous));
  }

  /**
   * The collection lists only the public file.
   */
  public function testCollectionOmitsPrivateFile(): void {
    $response = $this->get('/jsonapi/file/file');
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $data = json_decode((string) $response->getContent(), TRUE)['data'];
    $uuids = array_column($data, 'id');
    $this->assertContains($this->publicFile->uuid(), $uuids);
    $this->assertNotContains($this->privateFile->uuid(), $uuids);
    foreach ($data as $item) {
      $this->assertStringStartsNotWith('private://', $item['attributes']['uri']['value']);
    }
  }

  /**
   * Individual GETs: private is forbidden, public is fine.
   */
  public function testIndividualGet(): void {
    $private = $this->get('/jsonapi/file/file/' . $this->privateFile->uuid());
    $this->assertSame(403, $private->getStatusCode());
    $public = $this->get('/jsonapi/file/file/' . $this->publicFile->uuid());
    $this->assertSame(200, $public->getStatusCode(), (string) $public->getContent());
  }

  /**
   * Tests the service directly.
   *
   * @covers ::access
   */
  public function testServiceDecisions(): void {
    $service = $this->container->get('access_misc.jsonapi_private_file_access');
    $reflection = new \ReflectionProperty($service, 'requestStack');
    $original = $reflection->getValue($service);

    $plain = new RequestStack();
    $plain->push(Request::create('/'));
    $jsonapi_request = Request::create('/jsonapi/file/file');
    $jsonapi_request->attributes->set('_is_jsonapi', TRUE);
    $jsonapi = new RequestStack();
    $jsonapi->push($jsonapi_request);

    try {
      $reflection->setValue($service, $plain);
      $this->assertFalse($service->access($this->privateFile, 'view')->isForbidden());
      $this->assertTrue($service->access($this->privateFile, 'view')->isNeutral());

      $reflection->setValue($service, $jsonapi);
      $this->assertTrue($service->access($this->privateFile, 'view')->isForbidden());
      $this->assertTrue($service->access($this->privateFile, 'download')->isNeutral());
      $this->assertTrue($service->access($this->publicFile, 'view')->isNeutral());
    }
    finally {
      $reflection->setValue($service, $original);
    }
  }

}
