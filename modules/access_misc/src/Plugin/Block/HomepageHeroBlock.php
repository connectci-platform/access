<?php

namespace Drupal\access_misc\Plugin\Block;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides the ACCESS Support homepage "How can we help?" hero.
 *
 * @Block(
 *   id = "access_homepage_hero",
 *   admin_label = @Translation("Homepage hero (How can we help?)"),
 *   category = @Translation("Custom"),
 * )
 */
class HomepageHeroBlock extends BlockBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default block configuration.
   */
  public function defaultConfiguration() {
    return [
      'assistant_url' => 'https://qa.access-ci.org/',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The block configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form with the assistant URL field added.
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['assistant_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Assistant URL'),
      '#description' => $this->t('The question is appended as the <code>q</code> query parameter.'),
      '#default_value' => $this->configuration['assistant_url'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The block configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return void
   *   Nothing is returned.
   */
  public function blockValidate($form, FormStateInterface $form_state) {
    if (!self::isAllowedUrl((string) $form_state->getValue('assistant_url'))) {
      $form_state->setErrorByName('assistant_url', $this->t('The assistant URL must be an absolute http or https URL.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The block configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return void
   *   Nothing is returned.
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['assistant_url'] = $form_state->getValue('assistant_url');
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   A render array for the homepage-hero component. The "prompts" prop is a
   *   list of arrays with "label" and "url" keys.
   */
  public function build() {
    // Config can be changed by YAML import, bypassing blockValidate(), so
    // re-check here: a javascript: or malformed URL must never reach an href.
    $assistant_url = (string) ($this->configuration['assistant_url'] ?? '');
    if (!self::isAllowedUrl($assistant_url)) {
      $assistant_url = $this->defaultConfiguration()['assistant_url'];
    }
    $prompts = [];
    foreach ([
      (string) $this->t('How do I get started with ACCESS?'),
      (string) $this->t('Which resource has GPUs for deep learning?'),
      (string) $this->t('How do I move data with Globus?'),
    ] as $label) {
      $prompts[] = [
        'label' => $label,
        'url' => Url::fromUri($assistant_url, ['query' => ['q' => $label]])->toString(),
      ];
    }
    return [
      '#type' => 'component',
      '#component' => 'access_misc:homepage-hero',
      '#props' => [
        'bg' => Url::fromUserInput('/themes/contrib/asp-theme/images/cta/homepage-shapes.jpg')->toString(),
        'help_ticket_url' => Url::fromUserInput('/help-ticket')->toString(),
        'tickets_url' => 'https://access-ci.atlassian.net/servicedesk/customer/user/requests',
        'docs_url' => Url::fromUserInput('/documentation')->toString(),
        'assistant_url' => $assistant_url,
        'prompts' => $prompts,
      ],
    ];
  }

  /**
   * Checks that a URL is an absolute http or https URL.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is safe to use as the assistant URL.
   */
  protected static function isAllowedUrl(string $url): bool {
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], TRUE) && UrlHelper::isValid($url, TRUE);
  }

}
