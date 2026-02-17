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

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p>Configure the main logo URL for each domain. These values can be accessed using the [domain_logo] token.</p>'),
    ];

    $form['logos'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Domain Logos'),
      '#tree' => TRUE,
    ];

    foreach ($domains as $domain_id => $domain) {
      $form['logos'][$domain_id] = [
        '#type' => 'textfield',
        '#title' => $domain->label(),
        '#default_value' => $config->get('logos.' . $domain_id),
        '#description' => $this->t('Enter the URL or path to the logo for %domain', ['%domain' => $domain->label()]),
        '#maxlength' => 512,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('access_misc.domain_logos');
    $logos = $form_state->getValue('logos');

    foreach ($logos as $domain_id => $logo_url) {
      $config->set('logos.' . $domain_id, $logo_url);
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
