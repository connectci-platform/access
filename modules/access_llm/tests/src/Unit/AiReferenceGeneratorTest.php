<?php

declare(strict_types=1);

namespace Drupal\Tests\access_llm\Unit;

use Drupal\access_llm\AiReferenceGenerator;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepository;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\Tests\UnitTestCase;
use OpenAI\Contracts\ClientContract;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;

/**
 * Tests the model and response handling of the OpenAI call.
 *
 * D8-3013: suggestions failed because the call always used the retired gpt-4.
 *
 * @coversDefaultClass \Drupal\access_llm\AiReferenceGenerator
 * @group access_llm
 */
class AiReferenceGeneratorTest extends UnitTestCase {

  /**
   * Builds a generator whose OpenAI client answers with $content.
   *
   * @param string $content
   *   The message content the fake model returns.
   * @param array $settings
   *   The access_llm.settings values.
   * @param array $terms
   *   Taxonomy tree items, as loadTree() returns them.
   *
   * @return array{0: \Drupal\access_llm\AiReferenceGenerator, 1: \OpenAI\Testing\ClientFake}
   *   The generator and the fake client it calls.
   */
  private function generator(string $content, array $settings = [], array $terms = []): array {
    $fake = new ClientFake([
      CreateResponse::fake(['choices' => [['message' => ['content' => $content]]]]),
    ]);

    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('test-key');
    $keys = $this->createMock(KeyRepository::class);
    $keys->method('getKey')->willReturn($key);

    $storage = $this->createMock(TermStorageInterface::class);
    $storage->method('loadTree')->willReturn($terms);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggers->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $generator = new class(
      $keys,
      $entityTypeManager,
      $this->createMock(RendererInterface::class),
      $this->getConfigFactoryStub(['access_llm.settings' => $settings]),
      $this->createMock(AccountInterface::class),
      $loggers,
      $this->createMock(ThemeInitializationInterface::class),
      $this->createMock(ThemeManagerInterface::class),
    ) extends AiReferenceGenerator {

      /**
       * The client handed out instead of a real one.
       */
      public ClientContract $fake;

      /**
       * {@inheritdoc}
       */
      protected function client(string $api_key): ClientContract {
        return $this->fake;
      }

    };
    $generator->fake = $fake;
    return [$generator, $fake];
  }

  /**
   * Returns the parameters of the one chat request that was sent.
   */
  private function sentPayload(ClientFake $fake): array {
    $payload = NULL;
    $fake->assertSent(Chat::class, function (string $method, array $parameters) use (&$payload): bool {
      $payload = $parameters;
      return $method === 'create';
    });
    return $payload;
  }

  /**
   * The model set in access_llm.settings is the one requested.
   */
  public function testConfiguredModelIsUsed(): void {
    [$generator, $fake] = $this->generator('{"summary": "Short."}', ['model' => 'gpt-5-mini']);
    $generator->generateSummaryPrompt(str_repeat('GPU training text. ', 10));

    $generator->summarySuggested();

    $this->assertSame('gpt-5-mini', $this->sentPayload($fake)['model']);
  }

  /**
   * With no model configured, the fallback is a current model, not gpt-4.
   */
  public function testUnsetModelFallsBackToCurrentModel(): void {
    [$generator, $fake] = $this->generator('{"summary": "Short."}');
    $generator->generateSummaryPrompt(str_repeat('GPU training text. ', 10));

    $generator->summarySuggested();

    $this->assertSame(AiReferenceGenerator::DEFAULT_MODEL, $this->sentPayload($fake)['model']);
    $this->assertNotSame('gpt-4', AiReferenceGenerator::DEFAULT_MODEL);
  }

  /**
   * The summary request uses JSON mode and its prompt asks for JSON.
   *
   * OpenAI rejects json_object mode unless the messages mention JSON.
   */
  public function testSummaryRequestsJsonAndSaysSo(): void {
    [$generator, $fake] = $this->generator('{"summary": "Short."}');
    $generator->generateSummaryPrompt(str_repeat('GPU training text. ', 10));

    $generator->summarySuggested();

    $payload = $this->sentPayload($fake);
    $this->assertSame(['type' => 'json_object'], $payload['response_format']);
    $this->assertStringContainsStringIgnoringCase('json', $payload['messages'][0]['content']);
  }

  /**
   * The summary is read out of the JSON answer.
   */
  public function testSummaryIsReadFromJson(): void {
    [$generator] = $this->generator('{"summary": "A short summary."}');
    $generator->generateSummaryPrompt(str_repeat('GPU training text. ', 10));

    $this->assertSame('A short summary.', $generator->summarySuggested());
  }

  /**
   * A plain-text answer is still returned as the summary.
   */
  public function testPlainTextSummaryIsReturnedAsIs(): void {
    [$generator] = $this->generator('A plain summary.');
    $generator->generateSummaryPrompt(str_repeat('GPU training text. ', 10));

    $this->assertSame('A plain summary.', $generator->summarySuggested());
  }

  /**
   * Tag suggestions use JSON mode and map the chosen names back to term ids.
   */
  public function testTagSuggestionsRequestJsonAndMapNamesToIds(): void {
    $terms = [
      (object) ['tid' => 11, 'name' => 'gpu', 'depth' => 1],
      (object) ['tid' => 12, 'name' => 'ai', 'depth' => 1],
    ];
    [$generator, $fake] = $this->generator('{"highly": ["gpu"], "moderately": ["ai"]}', [], $terms);
    $generator->generateTaxonomyPrompt('tags', 1, str_repeat('GPU training text. ', 10));

    $this->assertSame([11], $generator->taxonomyIdSuggested());
    $payload = $this->sentPayload($fake);
    $this->assertSame(['type' => 'json_object'], $payload['response_format']);
    $this->assertStringContainsStringIgnoringCase('json', $payload['messages'][0]['content']);
  }

}
