<?php

namespace Drupal\access\Plugin\Block;

use Drupal\access\EligibilityState;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Eligibility banner shown to ACCESS users with incomplete profiles.
 *
 * Reads request-scoped state from EligibilityState (populated by
 * EligibilityCheckSubscriber). Renders nothing when the state is
 * eligible or unknown; renders a warning banner with the reason and a
 * link to the allocations profile page when ineligible.
 *
 * @Block(
 *   id = "eligibility_banner_block",
 *   admin_label = @Translation("ACCESS Eligibility Banner"),
 *   category = @Translation("ACCESS")
 * )
 */
class EligibilityBannerBlock extends BlockBase implements ContainerFactoryPluginInterface {

  // Phase 2 will likely move this URL into config or a block plugin setting
  // so other ACCESS sites can configure their own profile-update target.
  const PROFILE_URL = 'https://allocations.access-ci.org/profile';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EligibilityState $state,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('access.eligibility_state'),
    );
  }

  public function build(): array {
    if (!$this->state->isKnown() || $this->state->isEligible()) {
      return [];
    }
    return [
      // role="status" is a polite live region (announced when the user
      // finishes their current task) — appropriate here because the
      // banner is persistent and the user is not in an emergency state.
      // If we want immediate screen-reader interruption ("Action needed
      // NOW"), switch to role="alert". Default to role="status" for
      // phase 1; revisit if accessibility review pushes back.
      '#type' => 'inline_template',
      '#template' => '<div class="access-eligibility-banner__inner container">
  <div class="access-eligibility-banner messages messages--bootstrap-warning" role="status">
    <span class="access-eligibility-banner__icon" aria-hidden="true">⚠</span>
    <span class="access-eligibility-banner__message">{{ action_label }} {{ reason }}</span>
    <a class="access-eligibility-banner__link" href="{{ profile_url }}" aria-label="{{ link_aria_label }}">{{ link_label }}</a>
  </div>
</div>',
      '#context' => [
        // t() returns TranslatableMarkup, which Twig treats as safe HTML.
        'action_label' => $this->t('Action needed:'),
        'link_label' => $this->t('Update profile'),
        'link_aria_label' => $this->t('Update your ACCESS allocations profile'),
        'reason' => $this->state->getReason(),
        'profile_url' => self::PROFILE_URL,
      ],
      // Library is declared in access.libraries.yml (Task 6).
      '#attached' => [
        'library' => ['access/eligibility_banner'],
      ],
    ];
  }

  public function getCacheMaxAge(): int {
    return 0;
  }

}
