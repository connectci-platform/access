<?php

namespace Drupal\access_affinitygroup\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\node\NodeStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deletes duplicate access_active_resources_from_cid nodes queued by update 10011.
 *
 * @QueueWorker(
 *   id = "access_affinitygroup_delete_duplicate_cider_nodes",
 *   title = @Translation("Delete duplicate CiDeR resource nodes"),
 *   cron = {"time" = 60}
 * )
 */
class DeleteDuplicateCiderResourceNodes extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected NodeStorageInterface $nodeStorage,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')->getStorage('node'),
    );
  }

  public function processItem($data): void {
    $nids = (array) $data;
    $nodes = $this->nodeStorage->loadMultiple($nids);
    if ($nodes) {
      $this->nodeStorage->delete($nodes);
    }
  }

}
