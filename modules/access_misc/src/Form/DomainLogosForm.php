<?php

namespace Drupal\access_misc\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Configure domain logos.
 */
class DomainLogosForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a DomainLogosForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'domain_logos_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['access_misc.domain_logos'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('access_misc.domain_logos');
    $domains = $this->entityTypeManager->getStorage('domain')->loadMultiple();

    $form['help'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p>Configure per-domain logos and descriptions. Tokens: <code>[access_misc:domain_logo]</code>, <code>[access_misc:domain_description]</code></p>'),
    ];

    foreach ($domains as $domain_id => $domain) {
      $form[$domain_id] = [
        '#type' => 'details',
        '#title' => $domain->label(),
        '#open' => FALSE,
      ];
      $form[$domain_id]['logos_' . $domain_id] = [
        '#type' => 'textfield',
        '#title' => $this->t('Logo path'),
        '#default_value' => $config->get('logos.' . $domain_id),
        '#maxlength' => 512,
      ];
      $form[$domain_id]['descriptions_' . $domain_id] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#default_value' => $config->get('descriptions.' . $domain_id),
        '#description' => $this->t('Used for front page meta description and og:description.'),
        '#rows' => 2,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('access_misc.domain_logos');
    $domains = $this->entityTypeManager->getStorage('domain')->loadMultiple();

    foreach ($domains as $domain_id => $domain) {
      $config->set('logos.' . $domain_id, $form_state->getValue('logos_' . $domain_id));
      $config->set('descriptions.' . $domain_id, $form_state->getValue('descriptions_' . $domain_id));
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
