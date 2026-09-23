<?php

namespace Drupal\cssn\Plugin\simple_sitemap\UrlGenerator;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\cssn\Plugin\Util\PersonaSitemapLookup;
use Drupal\simple_sitemap\Logger;
use Drupal\simple_sitemap\Plugin\simple_sitemap\SimpleSitemapPluginBase;
use Drupal\simple_sitemap\Plugin\simple_sitemap\UrlGenerator\UrlGeneratorBase;
use Drupal\simple_sitemap\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates URLs for published community persona pages.
 *
 * The pages live on the cssn.public_page route rather than on an entity, so
 * the entity based generators never see them. Each domain gets the personas of
 * the regions connected to it; domains without a region of their own (the
 * Connect CI hub, CoCo, USRSE) get none.
 *
 * @UrlGenerator(
 *   id = "community_persona",
 *   label = @Translation("Community persona URL generator"),
 *   description = @Translation("Generates URLs for published community persona pages of the sitemap's domain."),
 * )
 */
final class CommunityPersonaUrlGenerator extends UrlGeneratorBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The community persona sitemap lookup.
   *
   * @var \Drupal\cssn\Plugin\Util\PersonaSitemapLookup
   */
  protected $personaLookup;

  /**
   * Number of personas per data set.
   *
   * @var int
   */
  protected $personasPerDataset;

  /**
   * CommunityPersonaUrlGenerator constructor.
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\simple_sitemap\Logger $logger
   *   Simple XML Sitemap logger.
   * @param \Drupal\simple_sitemap\Settings $settings
   *   The simple_sitemap.settings service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\cssn\Plugin\Util\PersonaSitemapLookup $persona_lookup
   *   The community persona sitemap lookup.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    Logger $logger,
    Settings $settings,
    EntityTypeManagerInterface $entity_type_manager,
    PersonaSitemapLookup $persona_lookup,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger, $settings);
    $this->entityTypeManager = $entity_type_manager;
    $this->personaLookup = $persona_lookup;
    $this->personasPerDataset = $this->settings->get('entities_per_queue_item', 50);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): SimpleSitemapPluginBase {

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('simple_sitemap.logger'),
      $container->get('simple_sitemap.settings'),
      $container->get('entity_type.manager'),
      $container->get('cssn.persona_sitemap_lookup')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int, array{domain: string, uids: array<int, string>}>
   *   Data sets, each holding a chunk of persona user IDs.
   */
  public function getDataSets(): array {
    $domain_id = $this->sitemap->getType()->getThirdPartySetting('domain_simple_sitemap', 'sitemap_domain');
    if (!$domain_id) {
      return [];
    }

    $region_tids = $this->personaLookup->getRegionTermIds($domain_id);
    $uids = $this->personaLookup->getPersonaUserIds($region_tids);

    $data_sets = [];
    foreach (array_chunk($uids, $this->personasPerDataset) as $chunk) {
      $data_sets[] = [
        'domain' => $domain_id,
        'uids' => $chunk,
      ];
    }

    return $data_sets;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int, array<string, mixed>>
   *   Path data for each persona in the data set.
   */
  public function generate($data_set): array {
    return $this->processDataSet($data_set);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int, array<string, mixed>>
   *   Path data for each persona in the data set.
   */
  protected function processDataSet($data_set): array {
    /** @var \Drupal\domain\DomainInterface|null $domain */
    $domain = $this->entityTypeManager->getStorage('domain')->load($data_set['domain']);
    if ($domain === NULL) {
      return [];
    }
    // The route is not entity based, so nothing rewrites its host for us. Build
    // the URL against the sitemap's domain the way domain_simple_sitemap does.
    $base_url = $domain->getScheme() . $domain->getCanonical();

    $user_storage = $this->entityTypeManager->getStorage('user');
    $path_data = [];
    foreach ($user_storage->loadMultiple($data_set['uids']) as $user) {
      $path = Url::fromRoute('cssn.public_page', ['uid' => $user->id()])->toString();
      $path_data[] = [
        'url' => $base_url . $path,
        'lastmod' => date('c', $user->getChangedTime()),
        'priority' => '0.5',
        'changefreq' => 'monthly',
        'images' => [],
        'meta' => [
          'path' => ltrim($path, '/'),
        ],
      ];
    }
    // Keep memory flat over the whole run.
    $user_storage->resetCache($data_set['uids']);

    return $path_data;
  }

}
