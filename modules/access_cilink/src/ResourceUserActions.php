<?php

namespace Drupal\access_cilink;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\flag\FlagLinkBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lazy builders for the per-user parts of a KB resource.
 *
 * The like widget, the Edit button and the "Flag as" menu depend on the
 * current user (CSRF tokens, flag state, ownership, roles), so they must not
 * be baked into render-cached markup. They are built here and injected as
 * placeholders.
 *
 * The $variant argument is a scalar string of the form "<kind>:<domain>"
 * where kind is "listing" or "page" and domain is the domain CSS class (for
 * example "access-support"). It only selects between fixed markup variants;
 * it is never printed. Unknown values fall back to the listing markup.
 */
class ResourceUserActions implements TrustedCallbackInterface {

  use StringTranslationTrait;

  /**
   * Classes added to the report flag links.
   */
  const REPORT_FLAG_CLASSES = 'no-underline text-dark-teal hover--underline';

  /**
   * Constructs a ResourceUserActions object.
   */
  public function __construct(
    protected AccountInterface $currentUser,
    protected FlagLinkBuilderInterface $flagLinkBuilder,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RequestStack $requestStack,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['likeWidget', 'userActions'];
  }

  /**
   * Lazy builder: the like widget (upvote flag or login prompt).
   *
   * @param int|string $sid
   *   The webform submission ID.
   * @param string $variant
   *   Markup variant, "<kind>:<domain>".
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function likeWidget(int|string $sid, string $variant): array {
    [$kind, $domain] = $this->parseVariant($variant);
    $build = [];
    if ($this->currentUser->isAnonymous()) {
      $uri = $this->requestStack->getCurrentRequest()?->getRequestUri() ?? '/';
      $login = [
        '#type' => 'link',
        '#title' => $kind === 'page' ? $this->t('Login to vote') : $this->t('Login to like'),
        '#url' => Url::fromRoute('user.login', [], [
          'query' => ['destination' => $uri],
          'attributes' => ['class' => ['text-dark-teal', 'no-underline', 'hover--underline']],
        ]),
      ];
      if ($kind === 'page') {
        $template = $domain === 'access-support'
          ? '<div class="flex"><i class="fa-light fa-right-to-bracket text-[32px] w-9 me-2"></i>{{ login }}</div>'
          : '<div class="d-flex"><div class="px46"><i class="fa-light fa-right-to-bracket ms-2 ml-2"></i></div> {{ login }}</div>';
      }
      else {
        $template = "<div class=''><i class='bi-box-arrow-in-right text-dark-teal text-[32px] w-9 me-2'></i><br> {{ login }}</div>";
      }
      $build = [
        '#type' => 'inline_template',
        '#template' => $template,
        '#context' => ['login' => $login],
      ];
    }
    else {
      $flag = $this->flagLinkBuilder->build('webform_submission', $sid, 'upvote', 'full');
      $flag['#attributes']['class'][] = 'text-decoration-none';
      $flag['#attributes']['class'][] = 'fw-normal';
      $build = [
        '#type' => 'inline_template',
        '#template' => $kind === 'page' ? '{{ flag }}' : "<div class=''>{{ flag }}</div>",
        '#context' => ['flag' => $flag],
      ];
    }
    $build['#cache']['contexts'] = $this->cacheContexts();
    return $build;
  }

  /**
   * Lazy builder: the Edit button and the "Flag as" menu.
   *
   * @param int|string $sid
   *   The webform submission ID.
   * @param string $variant
   *   Markup variant, "<kind>:<domain>".
   *
   * @return array<string, mixed>
   *   A render array. Empty (apart from cache metadata) for anonymous users.
   */
  public function userActions(int|string $sid, string $variant): array {
    $build = ['#cache' => ['contexts' => $this->cacheContexts()]];
    if ($this->currentUser->isAnonymous()) {
      return $build;
    }
    [$kind, $domain] = $this->parseVariant($variant);

    $submission = $this->entityTypeManager->getStorage('webform_submission')->load($sid);
    if (!$submission) {
      return $build;
    }

    $roles = $this->currentUser->getRoles();
    $edit = [];
    if ((int) $this->currentUser->id() === (int) $submission->getOwnerId() || in_array('administrator', $roles) || in_array('kb_pm', $roles)) {
      $edit = [
        '#type' => 'link',
        '#title' => $this->t('Edit'),
        '#url' => Url::fromRoute('entity.webform_submission.edit_form', [
          'webform' => $submission->getWebform()->id(),
          'webform_submission' => $submission->id(),
        ], [
          'attributes' => [
            'class' => $kind === 'page'
              ? ['btn', 'btn-sm', 'btn-secondary-outline', 'py-0', 'px-4', 'w-full', 'btn-md-teal']
              : ['w-full', 'btn-sm', 'btn-small', 'btn-secondary-outline', 'py-0', 'px-4', 'btn', 'btn-md-teal'],
          ],
        ]),
      ];
    }

    $flags = [];
    foreach (['outdated', 'not_useful', 'inaccurate'] as $flag_id) {
      $flag = $this->flagLinkBuilder->build('webform_submission', $sid, $flag_id, 'full');
      $flag['#attributes']['class'][] = self::REPORT_FLAG_CLASSES;
      $flags[$flag_id] = $flag;
    }

    if ($kind === 'page') {
      $report = $this->t('Flag this resource');
      $chevron = FALSE;
      if ($domain === 'access-support') {
        $template = '{% if edit %}<div class="mt-5">{{ edit }}</div>{% endif %}
          <details class="border border-solid border-dark-teal open:bg-white duration-300 relative">
            <summary class="ps-5 pe-10 py-2 text-dark-teal leading-5 cursor-pointer uppercase">{{ report }}</summary>
            <div class="bg-white px-5">
              <ul class="dropdown-menu">
                <li>{{ outdated }}</li>
                <li>{{ not_useful }}</li>
                <li>{{ inaccurate }}</li>
              </ul>
            </div>
          </details>';
      }
      else {
        $template = '{% if edit %}<div class="mt-5">{{ edit }}</div>{% endif %}
          <div class="dropdown mt-3">
            <a class="btn btn-outline-dark btn-outline-primary btn-sm dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              {{ report }}
            </a>
            <ul class="dropdown-menu">
              <li>{{ outdated }}</li>
              <li>{{ not_useful }}</li>
              <li>{{ inaccurate }}</li>
            </ul>
          </div>';
      }
    }
    else {
      $report = $this->t('Flag as');
      $chevron = $domain !== 'access-support';
      $template = '<div class="pt-4 md--w-full">
          {{ edit }}
          <details class="border border-solid border-dark-teal open:bg-white duration-300 relative">
            <summary class="ps-2 pe-10 py-2 text-dark-teal leading-5 uppercase">
              {{ report }}{% if chevron %} <i class="bi-chevron-down ml-3"></i>{% endif %}
            </summary>
            <ul class="px-2 mb-2">
              <li>{{ outdated }}</li>
              <li>{{ not_useful }}</li>
              <li>{{ inaccurate }}</li>
            </ul>
          </details>
        </div>';
    }

    $build += [
      '#type' => 'inline_template',
      '#template' => $template,
      '#context' => [
        'edit' => $edit,
        'report' => $report,
        'chevron' => $chevron,
        'outdated' => $flags['outdated'],
        'not_useful' => $flags['not_useful'],
        'inaccurate' => $flags['inaccurate'],
      ],
    ];
    return $build;
  }

  /**
   * Splits and validates a variant string.
   *
   * @return string[]
   *   The kind ("listing" or "page") and domain class ("access-support",
   *   "ccmnet" or "default").
   */
  protected function parseVariant(string $variant): array {
    [$kind, $domain] = array_pad(explode(':', $variant, 2), 2, '');
    $kind = $kind === 'page' ? 'page' : 'listing';
    $domain = in_array($domain, ['access-support', 'ccmnet'], TRUE) ? $domain : 'default';
    return [$kind, $domain];
  }

  /**
   * Cache contexts for all output of this class.
   *
   * @return string[]
   *   Cache contexts.
   */
  protected function cacheContexts(): array {
    return ['user', 'url.query_args'];
  }

}
