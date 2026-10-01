<?php

namespace Drupal\access_cilink;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\domain\DomainNegotiatorInterface;
use Drupal\domain\Entity\Domain;
use Drupal\flag\FlagCountManagerInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Single source of truth for which KB resources (CI links) are public.
 *
 * KB resources are "resource" webform submissions. A resource is public when
 * it is approved and not private. Domain is a listing concern, not an access
 * concern, so isPublic() does not look at it.
 */
class KbResourceRepository {

  /**
   * The webform that holds KB resources.
   */
  const WEBFORM_ID = 'resource';

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FlagCountManagerInterface $flagCount,
    protected DomainNegotiatorInterface $domainNegotiator,
  ) {}

  /**
   * Whether a submission is a public KB resource.
   *
   * Approved, and not private. A missing "private" value (older submissions)
   * counts as public. There is no domain check.
   */
  public function isPublic(WebformSubmissionInterface $submission): bool {
    if ($submission->getWebform()->id() !== self::WEBFORM_ID) {
      return FALSE;
    }
    $data = $submission->getData();
    if ((int) ($data['approved'] ?? 0) !== 1) {
      return FALSE;
    }
    return (int) ($data['private'] ?? 0) !== 1;
  }

  /**
   * Gets the sids of approved, non-private resources on a domain.
   *
   * @param string $domain_class
   *   The domain class, as returned by activeDomainClass().
   *
   * @return int[]
   *   Submission IDs, ascending.
   */
  public function getPublicSids(string $domain_class): array {
    $tids = $this->getRegionTids($domain_class);
    if (!$tids) {
      return [];
    }

    $query = $this->database->select('webform_submission_data', 'approved');
    $query->distinct();
    $query->fields('approved', ['sid']);
    $query->condition('approved.webform_id', self::WEBFORM_ID);
    $query->condition('approved.name', 'approved');
    $query->condition('approved.value', '1');

    $query->join('webform_submission_data', 'dom', 'dom.sid = approved.sid');
    $query->condition('dom.webform_id', self::WEBFORM_ID);
    $query->condition('dom.name', 'domain');
    $query->condition('dom.value', $tids, 'IN');

    $private = $this->database->select('webform_submission_data', 'priv');
    $private->addExpression('1');
    $private->where('priv.sid = approved.sid');
    $private->condition('priv.webform_id', self::WEBFORM_ID);
    $private->condition('priv.name', 'private');
    $private->condition('priv.value', '1');
    $query->notExists($private);

    $query->orderBy('approved.sid', 'ASC');

    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Gets the newest "changed" time of the public set, as ISO 8601.
   *
   * @return string|null
   *   The timestamp, or NULL when the set is empty.
   */
  public function getLastModified(string $domain_class): ?string {
    $sids = $this->getPublicSids($domain_class);
    if (!$sids) {
      return NULL;
    }
    $query = $this->database->select('webform_submission', 'ws');
    $query->addExpression('MAX(ws.changed)', 'last_changed');
    $query->condition('ws.sid', $sids, 'IN');
    $changed = $query->execute()->fetchField();
    return $changed ? date('c', (int) $changed) : NULL;
  }

  /**
   * Builds the JSON entry for one submission.
   *
   * @return array<string, mixed>
   *   The entry.
   */
  public function buildEntry(WebformSubmissionInterface $submission): array {
    $data = $submission->getData();
    $sid = (int) $submission->id();

    $category_id = (string) ($data['category'] ?? '');
    $element = $submission->getWebform()->getElement('category');
    $category = $element['#options'][$category_id] ?? $category_id;

    $flag_counts = $this->flagCount->getEntityFlagCounts($submission);

    $domains = [];
    foreach ($this->loadTerms($data['domain'] ?? []) as $term) {
      $value = $term->hasField('field_region_connected_domain')
        ? (string) $term->get('field_region_connected_domain')->value
        : '';
      if ($value !== '') {
        $domains[] = $value;
      }
    }

    $path = '/knowledge-base/resources/' . $sid;
    $domain = $this->domainNegotiator->getActiveDomain();

    return [
      'id' => $sid,
      'title' => (string) ($data['title'] ?? ''),
      'description' => $this->htmlToText((string) ($data['description_html']['value'] ?? '')),
      'links' => $this->normalizeLinks($data['link_to_resource'] ?? []),
      'url' => $domain instanceof Domain ? $domain->buildUrl($path) : $path,
      'category' => (string) $category,
      'category_id' => $category_id,
      'tags' => $this->termNames($data['tags'] ?? []),
      'skill_level' => $this->termNames($data['skill_level'] ?? []),
      'vote_count' => (int) ($flag_counts['upvote'] ?? 0),
      'domain' => array_values(array_unique($domains)),
      'last_modified' => date('c', (int) $submission->getChangedTime()),
    ];
  }

  /**
   * Gets the active domain class, as the search view computes it.
   *
   * Same value as Html::getClass([domain:name]).
   */
  public function activeDomainClass(): string {
    $domain = $this->domainNegotiator->getActiveDomain();
    return $domain instanceof Domain ? Html::getClass($domain->label()) : '';
  }

  /**
   * Gets the region term IDs mapped to a domain class.
   *
   * @return int[]
   *   Term IDs.
   */
  protected function getRegionTids(string $domain_class): array {
    if ($domain_class === '') {
      return [];
    }
    $tids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'region')
      ->condition('field_region_connected_domain', $domain_class)
      ->execute();
    return array_map('intval', array_values($tids));
  }

  /**
   * Loads terms by ID, in order, skipping those that no longer exist.
   *
   * @param mixed $tids
   *   A term ID or list of term IDs.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   The terms.
   */
  protected function loadTerms(mixed $tids): array {
    $tids = array_filter((array) $tids, 'is_scalar');
    if (!$tids) {
      return [];
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
    $ordered = [];
    foreach ($tids as $tid) {
      if (isset($terms[$tid])) {
        $ordered[] = $terms[$tid];
      }
    }
    return $ordered;
  }

  /**
   * Gets term names for a list of term IDs.
   *
   * @return string[]
   *   The names.
   */
  protected function termNames(mixed $tids): array {
    return array_map(fn($term) => (string) $term->label(), $this->loadTerms($tids));
  }

  /**
   * Normalizes webform_link values.
   *
   * @return array<int, array{title: string, url: string}>
   *   Links with a valid absolute URL.
   */
  protected function normalizeLinks(mixed $links): array {
    $result = [];
    foreach ((array) $links as $link) {
      if (!is_array($link)) {
        continue;
      }
      $url = trim((string) ($link['url'] ?? ''));
      $title = trim((string) ($link['title'] ?? ''));
      if ($url === '' && $title !== '' && UrlHelper::isValid($title, TRUE)) {
        $url = $title;
      }
      if ($url === '' || !UrlHelper::isValid($url, TRUE)) {
        continue;
      }
      $result[] = ['title' => $title !== '' ? $title : $url, 'url' => $url];
    }
    return $result;
  }

  /**
   * Converts description HTML to plain text, keeping block boundaries.
   */
  protected function htmlToText(string $html): string {
    $text = preg_replace('#<br\s*/?>|</(?:p|li|h[1-6]|div)>#i', "\n", $html);
    $text = Html::decodeEntities(strip_tags($text));
    // Non-breaking spaces count as spaces.
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/ ?\n ?/', "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
  }

}
