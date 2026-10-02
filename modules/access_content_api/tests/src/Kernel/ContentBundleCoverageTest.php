<?php

namespace Drupal\Tests\access_content_api\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\path_alias\Entity\PathAlias;

/**
 * Kernel tests for text-display coverage of the non-page node bundles.
 *
 * Each bundle gets a "text" display with only its public fields and a Layout
 * Builder default layout that ALSO places a private field. The API must serve
 * the public values and never the private one, because the text display (not
 * the default layout) is the source of truth.
 *
 * @group access_content_api
 */
class ContentBundleCoverageTest extends ContentApiKernelTestBase {

  /**
   * Data provider: bundle, public fields, private fields.
   *
   * Each field is [spec, value]. The fields mirror the picks in the spec.
   *
   * @return array<string, array>
   *   Test cases.
   */
  public static function bundleProvider(): array {
    $long = fn(string $label) => ['type' => 'text_long', 'label' => $label];
    $short = fn(string $label) => ['type' => 'string', 'label' => $label];
    $term = fn(string $label) => [
      'type' => 'entity_reference',
      'label' => $label,
      'target_type' => 'taxonomy_term',
    ];
    $link = fn(string $label) => ['type' => 'link', 'label' => $label];
    return [
      'affinity_group' => [
        'affinity_group',
        [
          'body' => [['type' => 'text_long'], 'AG body sentinel text'],
          'field_ag_goals' => [$long('Goals'), 'AG goals sentinel text'],
        ],
        [
          'field_coordinator' => [$short('Coordinator'), 'Coordinator Secret Person'],
        ],
      ],
      'mentorship_engagement' => [
        'mentorship_engagement',
        [
          'body' => [['type' => 'text_long'], 'Mentorship body sentinel text'],
          // The value is the term name; buildFixture() creates the term.
          'field_me_state' => [$term('State'), 'Mentorship state sentinel'],
        ],
        [
          'field_mentor_name' => [$short('Mentor'), 'Mentor Secret Person'],
        ],
      ],
      'access_news' => [
        'access_news',
        [
          'body' => [['type' => 'text_long'], 'News body sentinel text'],
          'field_affiliation' => [$short('Affiliation'), 'News affiliation sentinel'],
        ],
        [
          'field_news_notes' => [$short('Notes'), 'News internal notes secret'],
        ],
      ],
      'match_engagement' => [
        'match_engagement',
        [
          'body' => [['type' => 'text_long'], 'Match body sentinel text'],
          'field_qualifications' => [$long('Qualifications'), 'Match qualifications sentinel'],
        ],
        [
          'field_researcher' => [$short('Researcher'), 'Researcher Secret Person'],
        ],
      ],
      'appverse_app' => [
        'appverse_app',
        [
          'body' => [['type' => 'text_long'], 'App body sentinel text'],
          'field_appverse_github_url' => [$link('GitHub'), 'https://github.com/example/app-github-sentinel'],
        ],
        [
          'field_appverse_maintainer_name' => [$short('Maintainer'), 'Maintainer Secret Person'],
        ],
      ],
    ];
  }

  /**
   * Builds the createTextBundle() field specs and node values for a case.
   *
   * @return array
   *   [public specs, private specs, node values].
   */
  private function buildFixture(array $public, array $private): array {
    $values = [];
    $specs = [];
    foreach (['public' => $public, 'private' => $private] as $group => $fields) {
      $specs[$group] = [];
      foreach ($fields as $name => [$spec, $value]) {
        $specs[$group][$name] = $spec;
        $values[$name] = match ($spec['type']) {
          'text_long' => ['value' => '<p>' . $value . '</p>', 'format' => 'basic_html'],
          'link' => ['uri' => $value, 'title' => ''],
          'entity_reference' => [['target_id' => $this->createTerm($value)->id()]],
          default => $value,
        };
      }
    }
    return [$specs['public'], $specs['private'], $values];
  }

  /**
   * Public fields are served, private ones are not, for every new bundle.
   *
   * @dataProvider bundleProvider
   */
  public function testBundleServesOnlyPublicFields(string $bundle, array $public, array $private): void {
    [$public_specs, $private_specs, $values] = $this->buildFixture($public, $private);
    $this->createTextBundle($bundle, $public_specs, $private_specs);
    $title = 'Coverage Title For ' . $bundle;
    $node = $this->createContentNode($bundle, $values + ['title' => $title]);

    // Precondition: the default Layout Builder layout would leak the private
    // values, so the assertions below prove the API ignores that layout.
    $this->assertPrivateValuesWouldLeakFromDefaultLayout($node, $private);

    $response = $this->requestById($node->id());
    $this->assertSame(200, $response->getStatusCode());
    $data = $this->decode($response);
    $this->assertSame($bundle, $data['content_type']);

    foreach ($public as $name => [$spec, $value]) {
      $this->assertStringContainsString($value, $data['text'], "Public field $name is in the text.");
      // Term references use the configured inline label ("State: value").
      if ($spec['type'] === 'entity_reference') {
        $this->assertStringContainsString($spec['label'] . ': ' . $value, $data['text'], "Reference field $name reads Label: value.");
      }
    }
    foreach ($private as $name => [, $value]) {
      $this->assertStringNotContainsString($value, $data['text'], "Private field $name is not in the text.");
    }

    // No node template wrapper: the text must not open with the title.
    $this->assertFalse(str_starts_with(trim($data['text']), $title), 'Text does not start with the node title.');
    $this->assertStringNotContainsString($title, $data['text']);

    // The by-path endpoint serves the identical document.
    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/coverage-' . $bundle,
    ])->save();
    $by_path = $this->requestByPath('/coverage-' . $bundle);
    $this->assertSame(200, $by_path->getStatusCode());
    $path_data = $this->decode($by_path);
    $this->assertSame($data['text'], $path_data['text']);
    $this->assertSame($data['content_hash'], $path_data['content_hash']);
  }

  /**
   * An inline-labeled list field reads "Label: value" in the text.
   */
  public function testInlineLabelReadsLabelColonValue(): void {
    $this->createMatchBundle();
    $node = $this->createContentNode('match_engagement', [
      'body' => ['value' => '<p>Match body for labels</p>', 'format' => 'basic_html'],
      'field_status' => 'complete',
    ]);

    $data = $this->decode($this->requestById($node->id()));
    $this->assertStringContainsString('Status: Complete', $data['text']);
    // Label and value share a line, not split as "Status\nComplete".
    $this->assertStringNotContainsString("Status\nComplete", $data['text']);
  }

  /**
   * A long-text field with an "above" label reads as a "### Label" heading.
   */
  public function testAboveLabelOnTextFieldRendersHeading(): void {
    $this->createMatchBundle();
    $node = $this->createContentNode('match_engagement', [
      'body' => ['value' => '<p>Body</p>', 'format' => 'basic_html'],
      'field_qualifications' => ['value' => '<p>Knows Python</p>', 'format' => 'basic_html'],
    ]);

    $data = $this->decode($this->requestById($node->id()));
    $this->assertStringContainsString("### Qualifications\n\nKnows Python", $data['text']);
  }

  /**
   * A hidden-label field (body) renders the bare value, with no label prefix.
   */
  public function testHiddenLabelHasNoPrefix(): void {
    $this->createMatchBundle();
    $node = $this->createContentNode('match_engagement', [
      'body' => ['value' => '<p>Bare body value</p>', 'format' => 'basic_html'],
      'field_status' => 'in_progress',
    ]);

    $text = $this->decode($this->requestById($node->id()))['text'];
    $this->assertStringContainsString('Bare body value', $text);
    $this->assertStringNotContainsString('Body:', $text);
    $this->assertStringNotContainsString('body:', $text);
    // The body is the first field, so the text opens with its value.
    $this->assertTrue(str_starts_with(trim($text), 'Bare body value'), 'Text opens with the unlabeled body, got: ' . json_encode($text));
  }

  /**
   * Asserts the bundle's default layout really renders its private fields.
   *
   * Renders each private field's component from the Layout Builder default
   * section. If this fails the fixture is wrong and the "private field is
   * absent" assertions would pass vacuously.
   */
  private function assertPrivateValuesWouldLeakFromDefaultLayout($node, array $private): void {
    $display = EntityViewDisplay::load('node.' . $node->bundle() . '.default');
    $this->assertTrue($display->isLayoutBuilderEnabled());
    $renderer = \Drupal::service('renderer');
    $rendered = '';
    foreach ($display->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        $plugin = $component->getPlugin();
        $plugin->setContextValue('entity', $node);
        if (isset($plugin->getContextDefinitions()['view_mode'])) {
          $plugin->setContextValue('view_mode', 'default');
        }
        $build = $plugin->build();
        $rendered .= (string) $renderer->renderInIsolation($build);
      }
    }
    foreach ($private as $name => [, $value]) {
      $this->assertStringContainsString($value, $rendered, "Default layout renders private field $name.");
    }
  }

}
