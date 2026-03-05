<?php

namespace Drupal\access_misc\Plugin;

use Drupal\Core\Render\RendererInterface;
use Drupal\views\Views;

/**
 * Tag Cloud on nodes.
 *
 * @Login (
 *   id = "node_add_tags",
 *   title = @Translation("Tag Cloud"),
 *   description = @Translation("Pull in tags from view to create a tag cloud.")
 * )
 */
class NodeAddTags {

  /**
   * The 'renderer' service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a NodeAddTags object.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The 'renderer' service.
   */
  public function __construct(
    RendererInterface $renderer,
  ) {
    $this->renderer = $renderer;
  }

  /**
   * Get the NodeAddTags view.
   */
  public function getView() {
    $view = Views::getView('node_add_tags');
    $view->setDisplay('block_1');
    $view->execute();
    $rendered = $view->render();
    return $this->renderer->render($rendered);
  }

}
