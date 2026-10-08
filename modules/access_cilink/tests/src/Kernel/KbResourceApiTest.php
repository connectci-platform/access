<?php

namespace Drupal\Tests\access_cilink\Kernel;

use Drupal\Core\Cache\CacheableResponseInterface;

/**
 * Kernel tests for GET /api/1.0/kb-resources.
 *
 * Requests go through the HTTP kernel, so route access and the internal page
 * cache run for real.
 *
 * @group access_cilink
 */
class KbResourceApiTest extends KbResourceKernelTestBase {

  const PATH = '/api/1.0/kb-resources';

  /**
   * Fetches the endpoint and returns the decoded JSON.
   *
   * @return array<string, mixed>
   *   The decoded body.
   */
  protected function fetch(string $host = self::SUPPORT_HOST, $user = NULL): array {
    $response = $this->get(self::PATH, $host, $user);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertIsArray($data);
    return $data;
  }

  /**
   * Returns the titles in a response, sorted.
   *
   * @param array<string, mixed> $data
   *   The decoded response.
   *
   * @return string[]
   *   Titles.
   */
  protected function titles(array $data): array {
    $titles = array_column($data['kb_resources'], 'title');
    sort($titles);
    return $titles;
  }

  /**
   * An approved, non-private submission on the requesting domain is returned.
   */
  public function testApprovedPublicIsReturned(): void {
    $submission = $this->createResource(['title' => 'Visible']);
    $data = $this->fetch();
    $this->assertSame(1, $data['version']);
    $this->assertSame('support', $data['domain']);
    $this->assertSame([(int) $submission->id()], array_column($data['kb_resources'], 'id'));
  }

  /**
   * Unapproved submissions are absent (the key regression).
   */
  public function testUnapprovedIsAbsent(): void {
    $this->createResource(['title' => 'Visible']);
    $this->createResource(['title' => 'Unapproved', 'approved' => 0]);
    $this->assertSame(['Visible'], $this->titles($this->fetch()));
  }

  /**
   * Private submissions are absent.
   */
  public function testPrivateIsAbsent(): void {
    $this->createResource(['title' => 'Visible']);
    $this->createResource(['title' => 'Secret', 'private' => 1]);
    $this->assertSame(['Visible'], $this->titles($this->fetch()));
  }

  /**
   * An approved submission with no private value (older rows) is returned.
   */
  public function testMissingPrivateValueIsPublic(): void {
    $this->createResource(['title' => 'Old one', 'private' => NULL]);
    $this->assertSame(['Old one'], $this->titles($this->fetch()));
  }

  /**
   * Domain scoping: other-only is absent, both-domains is returned.
   */
  public function testDomainScoping(): void {
    $this->createResource(['title' => 'Support only']);
    $this->createResource(['title' => 'Other only', 'domain' => [$this->otherRegion->id()]]);
    $this->createResource(['title' => 'Both', 'domain' => [$this->supportRegion->id(), $this->otherRegion->id()]]);

    $this->assertSame(['Both', 'Support only'], $this->titles($this->fetch()));
  }

  /**
   * The other domain gets its own set; responses are not shared across hosts.
   */
  public function testResponseVariesBySite(): void {
    $this->createResource(['title' => 'Support only']);
    $this->createResource(['title' => 'Other only', 'domain' => [$this->otherRegion->id()]]);
    $this->createResource(['title' => 'Both', 'domain' => [$this->supportRegion->id(), $this->otherRegion->id()]]);

    // Prime the page cache for the support host first, then ask the other
    // host and ask the support host again.
    $support = $this->fetch(self::SUPPORT_HOST);
    $other = $this->fetch(self::OTHER_HOST);
    $support_again = $this->fetch(self::SUPPORT_HOST);

    $this->assertSame(['Both', 'Support only'], $this->titles($support));
    $this->assertSame(['Both', 'Other only'], $this->titles($other));
    $this->assertSame('other', $other['domain']);
    $this->assertSame(['Both', 'Support only'], $this->titles($support_again));

    // URLs are built on the requesting domain.
    foreach ($other['kb_resources'] as $entry) {
      $this->assertStringStartsWith('https://' . self::OTHER_HOST . '/knowledge-base/resources/', $entry['url']);
    }
  }

  /**
   * An admin gets the same set as anonymous, even for hidden submissions.
   */
  public function testAdminSeesSameSetAsAnonymous(): void {
    $this->createResource(['title' => 'Visible']);
    $this->createResource(['title' => 'Unapproved', 'approved' => 0]);
    $this->createResource(['title' => 'Secret', 'private' => 1]);
    $admin = $this->createUser(['administrator']);

    $anonymous = $this->fetch();
    $as_admin = $this->fetch(self::SUPPORT_HOST, $admin);
    $this->assertSame(['Visible'], $this->titles($as_admin));
    $this->assertSame($anonymous['kb_resources'], $as_admin['kb_resources']);
  }

  /**
   * The entry has the documented shape.
   */
  public function testEntryShape(): void {
    $tag_a = $this->createTerm('tags', 'Python');
    $tag_b = $this->createTerm('tags', 'GPU');
    $level = $this->createTerm('skill_level', 'Beginner');
    $submission = $this->createResource([
      'title' => 'Shape',
      'category' => 'video_link',
      'tags' => [$tag_a->id(), $tag_b->id(), 99999],
      'skill_level' => [$level->id()],
      'description_html' => [
        'value' => '<p>Foo</p><p>Bar &amp; <strong>baz</strong></p>',
        'format' => 'basic_html',
      ],
      'link_to_resource' => [
        ['title' => 'Docs', 'url' => 'https://example.com/docs'],
        ['title' => 'https://example.org/title-only', 'url' => ''],
        ['title' => '', 'url' => ''],
        ['title' => 'Not a URL', 'url' => ''],
        ['title' => '', 'url' => 'https://example.net/no-title'],
      ],
    ]);

    $data = $this->fetch();
    $this->assertCount(1, $data['kb_resources']);
    $entry = $data['kb_resources'][0];

    $this->assertSame((int) $submission->id(), $entry['id']);

    // Links: url and title always present; title-only link repaired; empty and
    // non-URL links dropped; missing title falls back to the url.
    $this->assertSame([
      ['title' => 'Docs', 'url' => 'https://example.com/docs'],
      ['title' => 'https://example.org/title-only', 'url' => 'https://example.org/title-only'],
      ['title' => 'https://example.net/no-title', 'url' => 'https://example.net/no-title'],
    ], $entry['links']);
    foreach ($entry['links'] as $link) {
      $this->assertArrayHasKey('url', $link);
      $this->assertArrayHasKey('title', $link);
    }

    // Category label vs key.
    $this->assertSame('Video', $entry['category']);
    $this->assertSame('video_link', $entry['category_id']);

    // Terms are names; a deleted term is skipped.
    $this->assertSame(['Python', 'GPU'], $entry['tags']);
    $this->assertSame(['Beginner'], $entry['skill_level']);

    // Description is plain text with block boundaries kept.
    $this->assertSame("Foo\nBar & baz", $entry['description']);
    $this->assertSame($entry['description'], strip_tags($entry['description']));

    // Votes are an int.
    $this->assertSame(0, $entry['vote_count']);
    $this->assertSame(['support'], $entry['domain']);
    $this->assertSame(
      'https://' . self::SUPPORT_HOST . '/knowledge-base/resources/' . $submission->id(),
      $entry['url']
    );
    $this->assertSame(date('c', (int) $submission->getChangedTime()), $entry['last_modified']);
  }

  /**
   * A category key whose option no longer exists falls back to the key.
   */
  public function testUnknownCategoryFallsBackToKey(): void {
    $this->createResource(['category' => 'gone_option']);
    $entry = $this->fetch()['kb_resources'][0];
    $this->assertSame('gone_option', $entry['category']);
    $this->assertSame('gone_option', $entry['category_id']);
  }

  /**
   * A resource with only an invalid link is still returned, with no links.
   */
  public function testResourceWithoutValidLinksIsStillReturned(): void {
    $this->createResource([
      'title' => 'No links',
      'link_to_resource' => [['title' => 'junk', 'url' => '']],
    ]);
    $data = $this->fetch();
    $this->assertSame(['No links'], $this->titles($data));
    $this->assertSame([], $data['kb_resources'][0]['links']);
  }

  /**
   * The vote count reflects upvote flaggings.
   */
  public function testVoteCount(): void {
    $submission = $this->createResource();
    $flag = $this->container->get('entity_type.manager')->getStorage('flag')->load('upvote');
    foreach ([$this->createUser(), $this->createUser()] as $voter) {
      $this->container->get('flag')->flag($flag, $submission, $voter);
    }
    $entry = $this->fetch()['kb_resources'][0];
    $this->assertSame(2, $entry['vote_count']);
  }

  /**
   * Cache tags are bundle-scoped; the response varies by site and expires.
   */
  public function testCacheMetadata(): void {
    $this->createResource();
    $response = $this->get(self::PATH);
    $this->assertInstanceOf(CacheableResponseInterface::class, $response);
    $metadata = $response->getCacheableMetadata();
    $tags = $metadata->getCacheTags();

    $this->assertContains('webform_submission_list:resource', $tags);
    $this->assertContains('flagging_list:upvote', $tags);
    $this->assertContains('taxonomy_term_list:tags', $tags);
    $this->assertContains('taxonomy_term_list:skill_level', $tags);
    $this->assertContains('taxonomy_term_list:region', $tags);
    $this->assertContains('config:domain.record.support', $tags);
    $this->assertNotContains('webform_submission_list', $tags);
    $this->assertNotContains('taxonomy_term_list', $tags);

    $this->assertContains('url.site', $metadata->getCacheContexts());
    $this->assertSame(900, $metadata->getCacheMaxAge());
  }

  /**
   * The internal page cache really serves repeat anonymous requests.
   *
   * Guards the invalidation test below: without a cache hit it would pass
   * trivially.
   */
  public function testPageCacheIsInPlay(): void {
    $this->createResource();
    $first = $this->get(self::PATH);
    $this->assertSame('MISS', $first->headers->get('X-Drupal-Cache'));
    $second = $this->get(self::PATH);
    $this->assertSame('HIT', $second->headers->get('X-Drupal-Cache'));
  }

  /**
   * Saving a new approved resource invalidates the cached anonymous response.
   */
  public function testNewResourceInvalidatesPageCache(): void {
    $this->createResource(['title' => 'First']);
    $this->assertSame(['First'], $this->titles($this->fetch()));
    $this->createResource(['title' => 'Second']);
    $this->assertSame(['First', 'Second'], $this->titles($this->fetch()));
  }

}
