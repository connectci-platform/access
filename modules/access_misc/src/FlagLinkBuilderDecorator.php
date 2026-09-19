<?php

namespace Drupal\access_misc;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\flag\FlagLinkBuilderInterface;

/**
 * Restores flag 5.0.x behaviour for anonymous users denied a flag.
 *
 * Flag 5.1.0 (upstream issue #3295483) returns a title-less, href-less render
 * array for anonymous users who cannot flag, where 5.0.3 returned cacheable
 * metadata only. That array renders as an empty <a>, which defeats every
 * `{% if link_flag %}` Twig fallback and every Views `empty:` option on the
 * site. Written against flag 5.1.0; revisit when upstream reworks this.
 */
final class FlagLinkBuilderDecorator implements FlagLinkBuilderInterface, TrustedCallbackInterface {

  public function __construct(
    private readonly FlagLinkBuilderInterface $inner,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['build'];
  }

  /**
   * {@inheritdoc}
   */
  public function build($entity_type_id, $entity_id, $flag_id, ?string $view_mode = 'default') {
    $build = $this->inner->build($entity_type_id, $entity_id, $flag_id, $view_mode);
    if (!$build || !$this->currentUser->isAnonymous() || isset($build['#title'])) {
      return $build;
    }
    $empty = [];
    CacheableMetadata::createFromRenderArray($build)->applyTo($empty);
    return $empty;
  }

}
