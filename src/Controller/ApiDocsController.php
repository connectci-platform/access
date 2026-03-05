<?php

namespace Drupal\access\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
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

    // Getting Started section at the top.
    $build['getting_started'] = [
      '#markup' => '<div class="api-getting-started" style="background: #f5f5f5; padding: 20px; border-radius: 5px; margin: 20px 0;">
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

    // Automatically discover all OpenAPI generators.
    $generators = $this->openApiGeneratorManager->getDefinitions();

    // Filter to only show ACCESS APIs (those starting with 'access_')
    // and group by API type to handle multiple versions.
    $access_apis = [];
    $api_groups = [];

    foreach ($generators as $id => $definition) {
      if (strpos($id, 'access_') === 0) {
        // Extract base API name and version if present
        // Examples: access_events, access_events_v23, access_announcements_v3.
        preg_match('/^(access_[a-z]+)(_v?[\d\.]+)?$/', $id, $matches);
        $base_name = $matches[1] ?? $id;

        if (!isset($api_groups[$base_name])) {
          $api_groups[$base_name] = [];
        }

        $api_groups[$base_name][$id] = $definition;
      }
    }

    // Sort each group by version (newest first) and flatten.
    foreach ($api_groups as $base_name => $versions) {
      // Sort by ID in reverse to get newest versions first.
      krsort($versions);
      foreach ($versions as $id => $definition) {
        $access_apis[$id] = $definition;
      }
    }

    if (!empty($access_apis)) {
      $build['apis'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['api-list']],
      ];

      $previous_base_name = '';
      foreach ($access_apis as $id => $definition) {
        // Extract base name for grouping.
        preg_match('/^(access_[a-z]+)(_v?[\d\.]+)?$/', $id, $matches);
        $base_name = $matches[1] ?? $id;
        $is_additional_version = ($base_name === $previous_base_name);

        // Create the route name based on the pattern we established.
        $route_name = 'access.api_docs_' . str_replace('access_', '', $id);

        // Check if the route exists.
        try {
          $url = Url::fromRoute($route_name);

          $api_block = [
            '#type' => 'container',
            '#attributes' => [
              'class' => $is_additional_version ? ['api-item', 'api-older-version'] : ['api-item'],
              'style' => $is_additional_version
                ? 'border: 1px solid #e5e5e5; padding: 15px 20px; margin: -10px 0 20px 40px; border-radius: 5px; background: #f9f9f9;'
                : 'border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 5px;',
            ],
          ];

          // Try to get description from the generator instance.
          try {
            $generator = $this->openApiGeneratorManager->createInstance($id);
            $spec = $generator->getSpecification();

            // Title (plain text) - add "older version" indicator if needed.
            $title_text = $definition['label'];
            if ($is_additional_version) {
              $title_text .= ' (Previous Version)';
            }
            $api_block['title'] = [
              '#type' => 'html_tag',
              '#tag' => $is_additional_version ? 'h4' : 'h3',
              '#value' => $title_text,
            ];

            if (!empty($spec['info']['description'])) {
              // Extract just the first paragraph before any markdown headers.
              $description = $spec['info']['description'];
              // Remove markdown headers and get first sentence/paragraph.
              $description = preg_replace('/##.*$/ms', '', $description);
              $description = trim(explode("\n", $description)[0]);

              $api_block['description'] = [
                '#type' => 'html_tag',
                '#tag' => 'p',
                '#value' => htmlspecialchars($description),
              ];
            }

            // Create a details container for technical info.
            $details = [];

            // Show version if available.
            if (!empty($spec['info']['version'])) {
              $details[] = '<strong>Version:</strong> ' . $spec['info']['version'];
            }

            // Show available endpoints with full path.
            if (!empty($spec['paths'])) {
              $endpoints = array_keys($spec['paths']);
              // Construct full endpoint URLs.
              $full_endpoints = array_map(function ($endpoint) use ($spec) {
                $base = !empty($spec['servers'][0]['url']) ? $spec['servers'][0]['url'] : '/api/2.2';
                return $base . $endpoint;
              }, $endpoints);
              $details[] = '<strong>Endpoint:</strong> <code>' . implode('</code>, <code>', $full_endpoints) . '</code>';
            }

            if (!empty($details)) {
              $api_block['details'] = [
                '#type' => 'html_tag',
                '#tag' => 'p',
                '#value' => implode(' | ', $details),
                '#attributes' => ['style' => 'font-size: 0.9em; color: #666;'],
              ];
            }

            // View documentation button - make it look like a button.
            $link = Link::fromTextAndUrl('View Interactive Documentation →', $url);
            $link_html = $link->toString();
            // Add inline styles to make it look like a button.
            $api_block['actions'] = [
              '#type' => 'html_tag',
              '#tag' => 'div',
              '#value' => str_replace('<a ', '<a style="display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; margin-top: 10px;" ', $link_html),
              '#attributes' => ['class' => ['api-actions']],
            ];
          }
          catch (\Exception $e) {
            // If we can't load the spec, just show basic info.
            $api_block['title'] = [
              '#type' => 'html_tag',
              '#tag' => 'h3',
              '#value' => Link::fromTextAndUrl($definition['label'], $url)->toString(),
            ];
          }

          $build['apis'][] = $api_block;
          $previous_base_name = $base_name;
        }
        catch (\Exception $e) {
          // Route doesn't exist, skip this API.
          continue;
        }
      }
    }

    // Add cache tags so this page updates when new APIs are added.
    $build['#cache'] = [
      'tags' => ['openapi_generator_plugins'],
    ];

    return $build;
  }

}
