<?php

namespace Drupal\access\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\openapi\Plugin\openapi\OpenApiGeneratorManager;

/**
 * Controller for API documentation landing page.
 */
class ApiDocsController extends ControllerBase {

  /**
   * The OpenAPI generator manager.
   *
   * @var \Drupal\openapi\Plugin\openapi\OpenApiGeneratorManager
   */
  protected $openApiGeneratorManager;

  /**
   * Constructs an ApiDocsController object.
   *
   * @param \Drupal\openapi\Plugin\openapi\OpenApiGeneratorManager $openapi_generator_manager
   *   The OpenAPI generator manager.
   */
  public function __construct(OpenApiGeneratorManager $openapi_generator_manager) {
    $this->openApiGeneratorManager = $openapi_generator_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.openapi.generator')
    );
  }

  /**
   * Returns the API documentation index page.
   */
  public function index() {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['api-docs-index']],
    ];

    $build['intro'] = [
      '#markup' => '<p>Welcome to the ACCESS Support API documentation.</p>',
    ];
    
    // Getting Started section at the top
    $build['getting_started'] = [
      '#markup' => '<div class="api-getting-started">
        <h3>Getting Started</h3>
        <p>Each API provides interactive Swagger documentation where you can:</p>
        <ul>
          <li>Explore available endpoints and parameters</li>
          <li>View request/response schemas</li>
          <li>Test API calls directly in your browser</li>
        </ul>
        <p><strong>Format:</strong> All APIs return data in JSON format.<br>
        <strong>Authentication:</strong> No authentication required - these are public APIs.</p>
      </div>',
    ];
    
    $build['apis_intro'] = [
      '#markup' => '<h3>Available APIs</h3><p>The following APIs are available for integration:</p>',
    ];

    // Automatically discover all OpenAPI generators
    $generators = $this->openApiGeneratorManager->getDefinitions();
    
    // Filter to only show ACCESS APIs (those starting with 'access_')
    // and group by API type to handle multiple versions
    $access_apis = [];
    $api_groups = [];
    
    foreach ($generators as $id => $definition) {
      if (strpos($id, 'access_') === 0) {
        // Extract base API name and version if present
        // Examples: access_events, access_events_v23, access_announcements_v3
        preg_match('/^(access_[a-z]+)(_v?[\d\.]+)?$/', $id, $matches);
        $base_name = $matches[1] ?? $id;
        
        if (!isset($api_groups[$base_name])) {
          $api_groups[$base_name] = [];
        }
        
        $api_groups[$base_name][$id] = $definition;
      }
    }
    
    // Sort each group by version (newest first) and flatten
    foreach ($api_groups as $base_name => $versions) {
      // Sort by ID in reverse to get newest versions first
      krsort($versions);
      foreach ($versions as $id => $definition) {
        $access_apis[$id] = $definition;
      }
    }

    if (!empty($api_groups)) {
      // Order the cards alphabetically by their display label (the latest
      // version's label within each group), so the listing reads A–Z rather
      // than in plugin-discovery order. The latest version is the highest ID
      // after a reverse key sort — the same one the render loop displays.
      $latest_label = function (array $versions): string {
        krsort($versions);
        $latest = reset($versions);
        return (string) ($latest['label'] ?? '');
      };
      uasort($api_groups, function ($a, $b) use ($latest_label) {
        return strcasecmp($latest_label($a), $latest_label($b));
      });

      $html = '';

      foreach ($api_groups as $base_name => $versions) {
        krsort($versions);
        $version_ids = array_keys($versions);
        $latest_id = $version_ids[0];
        $latest_def = $versions[$latest_id];

        try {
          $generator = $this->openApiGeneratorManager->createInstance($latest_id);
          $spec = $generator->getSpecification();
          $title = htmlspecialchars($latest_def['label']);
          $description = '';
          if (!empty($spec['info']['description'])) {
            $desc = preg_replace('/##.*$/ms', '', $spec['info']['description']);
            $description = '<p>' . htmlspecialchars(trim(explode("\n", $desc)[0])) . '</p>';
          }

          // Version selector
          $version_html = '';
          if (count($versions) > 1) {
            $options = '';
            foreach ($versions as $vid => $vdef) {
              $route_name = 'access.api_docs_' . str_replace('access_', '', $vid);
              try {
                $vurl = Url::fromRoute($route_name)->toString();
                $vgen = $this->openApiGeneratorManager->createInstance($vid);
                $vspec = $vgen->getSpecification();
                $ver = $vspec['info']['version'] ?? $vid;
                $is_latest = ($vid === $latest_id);
                $label = 'v' . $ver . ($is_latest ? ' (latest)' : '');
                $options .= '<option value="' . $vurl . '">' . $label . '</option>';
              }
              catch (\Exception $e) {}
            }
            $endpoint = !empty($spec['servers'][0]['url']) ? htmlspecialchars($spec['servers'][0]['url']) : '';
            $version_html = '<p class="api-docs__meta"><strong>Endpoint:</strong> <code>' . $endpoint . '</code> | <strong>Version:</strong> <select onchange="if(this.value)window.location=this.value">' . $options . '</select></p>';
          }
          else {
            $ver = $spec['info']['version'] ?? '';
            $endpoint = !empty($spec['servers'][0]['url']) ? htmlspecialchars($spec['servers'][0]['url']) : '';
            $version_html = '<p class="api-docs__meta"><strong>Version:</strong> ' . $ver . ' | <strong>Endpoint:</strong> <code>' . $endpoint . '</code></p>';
          }

          // Link to latest docs
          $latest_route = 'access.api_docs_' . str_replace('access_', '', $latest_id);
          $latest_url = Url::fromRoute($latest_route)->toString();
          $link_html = '<div class="api-docs__card-action"><a href="' . $latest_url . '" class="api-docs__button">View Interactive Documentation →</a></div>';

          $html .= '<div class="api-docs__card">'
            . '<h3>' . $title . '</h3>'
            . $description
            . $version_html
            . $link_html
            . '</div>';
        }
        catch (\Exception $e) {
          $html .= '<div class="api-docs__card"><h3>' . htmlspecialchars($latest_def['label']) . '</h3></div>';
        }
      }

      $build['apis'] = ['#markup' => Markup::create($html)];
    }


    // Add cache tags so this page updates when new APIs are added
    $build['#cache'] = [
      'tags' => ['openapi_generator_plugins'],
    ];

    return $build;
  }

}