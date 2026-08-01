<?php

namespace Drupal\access_affinitygroup\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * API controller for announcement (access_news) create/update/delete.
 *
 * These endpoints run AS the acting user (the ActingUserSwitchSubscriber has
 * switched the account by the time the controller runs), so node entity access
 * enforces the acting user's own permissions. On top of that, create/update
 * enforce the per-group coordinator check that the Drupal form #validate
 * callback (access_news_validate) applies — that callback does not run on this
 * non-form write path.
 *
 * SECURITY — draft-only: create HARDCODES moderation_state='draft' and ignores
 * any caller-supplied moderation_state/status/publish. update edits content
 * fields only and never touches moderation_state. There is no publish endpoint.
 * This prevents the AI caller from self-publishing.
 */
class AnnouncementApiController extends ControllerBase {

  /**
   * Content fields the endpoint accepts from the request body.
   *
   * moderation_state / status / publish are deliberately absent: create locks
   * the node to draft and update never changes the moderation state.
   */
  private const CONTENT_ATTRIBUTES = [
    'title',
    'field_published_date',
    'field_affiliation',
    'field_news_external_link',
    'field_choose_where_to_share_this',
  ];

  /**
   * POST /api/1.0/announcements — create a draft announcement.
   *
   * Named createAnnouncement (not create) because ControllerBase::create() is
   * the static service factory and cannot be overridden by an instance method.
   */
  public function createAnnouncement(Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid');
    if ($uid < 1) {
      return $this->refuse('forbidden', 'No acting user.', 403);
    }
    $user = $this->currentUser();

    $body = json_decode($request->getContent(), TRUE) ?: [];

    $groupNodes = $this->resolveGroupNodes($body['field_affinity_group_node'] ?? []);
    if (!$this->userMayPostToGroups($user, $groupNodes)) {
      return $this->refuse('not_coordinator', 'You are not a coordinator of every selected affinity group.', 409);
    }

    $values = [
      'type' => 'access_news',
      // Author = acting user. The switch already makes the acting user the
      // owner on save; set it explicitly for clarity (load-bearing: Task 9's
      // get_my_announcements relies on 'view own unpublished').
      'uid' => $uid,
      // Draft-only, hardcoded. Any caller moderation_state/status is ignored.
      'moderation_state' => 'draft',
      'title' => $body['title'] ?? '',
      'field_affinity_group_node' => array_map(fn (NodeInterface $n) => $n->id(), $groupNodes),
      'field_tags' => $this->resolveTagTerms($body['field_tags'] ?? []),
    ];
    $this->applyContentFields($values, $body, TRUE);

    $node = Node::create($values);

    if (!$node->access('create', $user, TRUE)->isAllowed()) {
      return $this->refuse('forbidden', 'You may not create announcements.', 403);
    }
    $node->save();

    return $this->success([
      'success' => TRUE,
      'uuid' => $node->uuid(),
      'nid' => (int) $node->id(),
      'title' => $node->getTitle(),
      'edit_url' => $this->editUrl($node),
    ]);
  }

  /**
   * GET /api/1.0/announcements/mine — the acting user's own announcements.
   *
   * Returns the acting user's own published announcements plus their own drafts,
   * newest first. Running switched as the acting user with accessCheck(TRUE)
   * scopes the query to what that user may view (own published + own unpublished
   * via 'view own unpublished content'); condition('uid', $uid) filters to their
   * authored nodes. Together these replace the mcp_bot god-mode read that could
   * see everyone's drafts.
   *
   * Reads ?limit=N (default 20) and returns AT MOST limit+1 items (range(0,
   * limit+1)) so the MCP client can compute has_more = count > limit.
   *
   * status is the STRING 'published'/'draft', and summary + edit_url are
   * server-computed, so the MCP client passes them through without transforming.
   */
  public function mine(Request $request): JsonResponse {
    $uid = (int) $request->attributes->get('acting_user_uid', 0);
    if ($uid < 1) {
      return $this->refuse('forbidden', 'No acting user.', 403);
    }
    $limit = max(1, (int) $request->query->get('limit', 20));
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->condition('type', 'access_news')
      ->condition('uid', $uid)
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->range(0, $limit + 1)
      ->execute();

    $items = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $items[] = [
        'uuid' => $node->uuid(),
        'nid' => (int) $node->id(),
        'title' => $node->label(),
        'status' => $node->isPublished() ? 'published' : 'draft',
        'created' => (int) $node->getCreatedTime(),
        'published_date' => $node->hasField('field_published_date') && !$node->get('field_published_date')->isEmpty()
          ? $node->get('field_published_date')->value
          : NULL,
        'summary' => $this->buildSummary($node),
        // Tags as english NAMES, never term ids — this is what the user sees.
        'tags' => $this->tagNames($node),
        'edit_url' => $this->editUrl($node),
      ];
    }

    return (new JsonResponse(['items' => $items]))->setPrivate()->setMaxAge(0);
  }

  /**
   * PATCH /api/1.0/announcements/{uuid} — update an announcement's content.
   */
  public function update(string $uuid, Request $request): JsonResponse {
    $node = $this->loadAnnouncement($uuid);
    if (!$node) {
      return $this->refuse('not_found', 'Announcement not found.', 404);
    }
    $user = $this->currentUser();

    if (!$node->access('update', $user, TRUE)->isAllowed()) {
      return $this->refuse('forbidden', 'You may not edit this announcement.', 403);
    }

    $body = json_decode($request->getContent(), TRUE) ?: [];

    // If the caller changes the affinity groups, re-run the coordinator check
    // against the NEW set before applying it.
    if (array_key_exists('field_affinity_group_node', $body)) {
      $groupNodes = $this->resolveGroupNodes($body['field_affinity_group_node']);
      if (!$this->userMayPostToGroups($user, $groupNodes)) {
        return $this->refuse('not_coordinator', 'You are not a coordinator of every selected affinity group.', 409);
      }
      $node->set('field_affinity_group_node', array_map(fn (NodeInterface $n) => $n->id(), $groupNodes));
    }

    // Tags replace the full set when provided (mirrors the current tool, which
    // sends the complete tag list on update).
    if (array_key_exists('field_tags', $body)) {
      $node->set('field_tags', $this->resolveTagTerms($body['field_tags']));
    }

    // Content fields only — NEVER moderation_state. Pass the node so a partial
    // body update (value OR summary alone) preserves the untouched half.
    $values = [];
    $this->applyContentFields($values, $body, FALSE, $node);
    foreach ($values as $field => $value) {
      $node->set($field, $value);
    }

    $node->save();

    return $this->success([
      'success' => TRUE,
      'uuid' => $node->uuid(),
      'title' => $node->getTitle(),
      'edit_url' => $this->editUrl($node),
    ]);
  }

  /**
   * DELETE /api/1.0/announcements/{uuid} — delete an announcement.
   */
  public function delete(string $uuid, Request $request): JsonResponse {
    $node = $this->loadAnnouncement($uuid);
    if (!$node) {
      return $this->refuse('not_found', 'Announcement not found.', 404);
    }
    $user = $this->currentUser();

    if (!$node->access('delete', $user, TRUE)->isAllowed()) {
      return $this->refuse('forbidden', 'You may not delete this announcement.', 403);
    }
    $node->delete();

    return $this->success([
      'success' => TRUE,
      'uuid' => $uuid,
    ]);
  }

  /**
   * Whether $user may post an announcement to all of $groupNodes.
   *
   * Replicates access_news_validate: administrator/news_pm may post to any
   * group; otherwise the user's uid must be in each group node's
   * field_coordinator (multi-value user reference).
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The acting user.
   * @param \Drupal\node\NodeInterface[] $groupNodes
   *   Already-loaded affinity_group nodes.
   */
  public function userMayPostToGroups(AccountInterface $user, array $groupNodes): bool {
    $roles = $user->getRoles();
    if (in_array('administrator', $roles, TRUE) || in_array('news_pm', $roles, TRUE)) {
      return TRUE;
    }
    foreach ($groupNodes as $group) {
      if (!$group || !$group->hasField('field_coordinator')) {
        return FALSE;
      }
      $isCoordinator = FALSE;
      foreach ($group->get('field_coordinator')->getValue() as $ref) {
        if ((int) $ref['target_id'] === (int) $user->id()) {
          $isCoordinator = TRUE;
          break;
        }
      }
      if (!$isCoordinator) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Resolves affinity-group UUIDs to loaded affinity_group nodes.
   *
   * The MCP sends affinity groups as node UUIDs. Each is resolved to its loaded
   * node so userMayPostToGroups() can read field_coordinator. Unknown UUIDs are
   * dropped, which makes the coordinator check fail closed (a group the user
   * cannot be a coordinator of is not silently posted to).
   *
   * @param mixed $uuids
   *   A list of node UUID strings (or a scalar for a single value).
   *
   * @return \Drupal\node\NodeInterface[]
   *   The loaded affinity_group nodes.
   */
  private function resolveGroupNodes($uuids): array {
    if (!is_array($uuids)) {
      $uuids = $uuids === NULL || $uuids === '' ? [] : [$uuids];
    }
    $storage = $this->entityTypeManager()->getStorage('node');
    $nodes = [];
    foreach ($uuids as $uuid) {
      if (!is_string($uuid) || $uuid === '') {
        continue;
      }
      $matches = $storage->loadByProperties(['uuid' => $uuid, 'type' => 'affinity_group']);
      if ($matches) {
        $nodes[] = reset($matches);
      }
    }
    return $nodes;
  }

  /**
   * Resolves an array of taxonomy_term UUIDs to term ids for field_tags.
   *
   * The MCP client resolves tag names to UUIDs and sends the UUIDs; unknown
   * UUIDs are dropped (fail-closed — a bogus tag simply isn't attached).
   */
  private function resolveTagTerms($uuids): array {
    if (!is_array($uuids)) {
      $uuids = $uuids === NULL || $uuids === '' ? [] : [$uuids];
    }
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $ids = [];
    foreach ($uuids as $uuid) {
      if (!is_string($uuid) || $uuid === '') {
        continue;
      }
      $matches = $storage->loadByProperties(['uuid' => $uuid]);
      if ($matches) {
        $ids[] = (int) reset($matches)->id();
      }
    }
    return $ids;
  }

  /**
   * The node's tag NAMES (english words) — never term ids.
   *
   * The user sees these; ids/uuids stay internal to the write path.
   */
  private function tagNames(NodeInterface $node): array {
    if (!$node->hasField('field_tags')) {
      return [];
    }
    $names = [];
    foreach ($node->get('field_tags')->referencedEntities() as $term) {
      $names[] = $term->label();
    }
    return $names;
  }

  /**
   * Copies the accepted content fields from the request body into $values.
   *
   * body is handled specially so the text format is pinned to basic_html and an
   * optional summary is supported. moderation_state/status are never copied.
   *
   * @param array $values
   *   The values array being built (by reference).
   * @param array $body
   *   The decoded request body.
   * @param bool $isCreate
   *   TRUE for create (only set present keys), TRUE/FALSE both only set keys the
   *   caller supplied so update leaves omitted fields untouched.
   */
  private function applyContentFields(array &$values, array $body, bool $isCreate, ?NodeInterface $existing = NULL): void {
    foreach (self::CONTENT_ATTRIBUTES as $field) {
      if (array_key_exists($field, $body)) {
        $values[$field] = $body[$field];
      }
    }
    if (array_key_exists('body', $body)) {
      $bodyIn = $body['body'];
      // Accept either a string or a {value, summary} shape.
      $current = $existing && $existing->hasField('body') && !$existing->get('body')->isEmpty()
        ? $existing->get('body')->first()->getValue()
        : [];
      // On a partial update, preserve the half the caller did not send.
      $value = is_array($bodyIn)
        ? ($bodyIn['value'] ?? ($current['value'] ?? ''))
        : (string) $bodyIn;
      $item = ['value' => $value, 'format' => 'basic_html'];
      if (is_array($bodyIn) && array_key_exists('summary', $bodyIn)) {
        $item['summary'] = $bodyIn['summary'];
      }
      elseif (isset($current['summary'])) {
        $item['summary'] = $current['summary'];
      }
      $values['body'] = $item;
    }
  }

  /**
   * Loads an access_news node by UUID, or NULL if it is not one.
   */
  private function loadAnnouncement(string $uuid): ?NodeInterface {
    $matches = $this->entityTypeManager()->getStorage('node')
      ->loadByProperties(['uuid' => $uuid]);
    $node = $matches ? reset($matches) : NULL;
    if ($node instanceof NodeInterface && $node->bundle() === 'access_news') {
      return $node;
    }
    return NULL;
  }

  /**
   * A short, HTML-stripped excerpt of the node body.
   *
   * Uses the body summary if it is set; otherwise strips tags from the body
   * value and truncates to 200 characters. Returns '' when there is no body.
   */
  private function buildSummary(NodeInterface $node): string {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return '';
    }
    $item = $node->get('body')->first()->getValue();
    $summary = trim((string) ($item['summary'] ?? ''));
    if ($summary !== '') {
      return trim(strip_tags($summary));
    }
    $value = trim(strip_tags((string) ($item['value'] ?? '')));
    if (mb_strlen($value) > 200) {
      $value = mb_substr($value, 0, 200);
    }
    return $value;
  }

  /**
   * The absolute edit URL for a saved node.
   */
  private function editUrl(NodeInterface $node): string {
    global $base_url;
    return $base_url . '/node/' . $node->id() . '/edit';
  }

  /**
   * A private, uncacheable success response.
   */
  private function success(array $payload): JsonResponse {
    return (new JsonResponse($payload))->setPrivate()->setMaxAge(0);
  }

  /**
   * Builds a refusal JsonResponse with the canonical {error, message} body.
   */
  private function refuse(string $code, string $message, int $status): JsonResponse {
    return (new JsonResponse(['error' => $code, 'message' => $message], $status))
      ->setPrivate()
      ->setMaxAge(0);
  }

}
