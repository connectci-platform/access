<?php

declare(strict_types=1);

namespace Drupal\access_events\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\Entity\EventSeriesTypeInterface;
use Drupal\recurring_events\EventInterface;

/**
 * Title callbacks for the eventseries form routes.
 *
 * Mirrors \Drupal\recurring_events\Controller\EventSeriesController's title
 * callbacks, but uses "@" placeholders instead of "%". The "%" placeholders
 * wrap each value in <em class="placeholder">, and easy_breadcrumb flattens
 * the title to a plain string and escapes it, so the breadcrumb rendered the
 * markup literally. RouteAlterSubscriber points the routes at these callbacks.
 */
class EventSeriesTitleController extends ControllerBase {

  /**
   * The _title_callback for the entity.eventseries.add_instance_form route.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $eventseries
   *   The eventseries.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function addInstanceTitle(EventSeries $eventseries): TranslatableMarkup {
    return $this->t('Add new instance to @name', ['@name' => $eventseries->label()]);
  }

  /**
   * The _title_callback for the entity.eventseries.add_form route.
   *
   * @param \Drupal\recurring_events\Entity\EventSeriesTypeInterface $eventseries_type
   *   The eventseries type.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function addPageTitle(EventSeriesTypeInterface $eventseries_type): TranslatableMarkup {
    return $this->t('Create @name Event', ['@name' => $eventseries_type->label()]);
  }

  /**
   * The _title_callback for the entity.eventseries.edit_form route.
   *
   * @param \Drupal\recurring_events\EventInterface $eventseries
   *   The eventseries.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function editPageTitle(EventInterface $eventseries): TranslatableMarkup {
    return $this->t('Edit @type Event @title', [
      '@type' => $eventseries->bundle(),
      '@title' => $eventseries->label(),
    ]);
  }

  /**
   * The _title_callback for the entity.eventseries.delete_form route.
   *
   * @param \Drupal\recurring_events\EventInterface $eventseries
   *   The eventseries.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function deletePageTitle(EventInterface $eventseries): TranslatableMarkup {
    return $this->t('Delete @type Event @title', [
      '@type' => $eventseries->bundle(),
      '@title' => $eventseries->label(),
    ]);
  }

  /**
   * The _title_callback for the entity.eventseries.clone_form route.
   *
   * @param \Drupal\recurring_events\EventInterface $eventseries
   *   The eventseries.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function clonePageTitle(EventInterface $eventseries): TranslatableMarkup {
    return $this->t('Clone @type Event @title', [
      '@type' => $eventseries->bundle(),
      '@title' => $eventseries->label(),
    ]);
  }

}
