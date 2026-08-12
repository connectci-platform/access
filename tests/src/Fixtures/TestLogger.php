<?php

namespace Drupal\Tests\access\Fixtures;

use Psr\Log\AbstractLogger;

/**
 * Capturing PSR-3 logger for assertions about log output.
 *
 * Interpolates the Drupal/PSR-3 placeholder styles ({name} and @name) so tests
 * assert on the rendered message rather than the raw template.
 */
class TestLogger extends AbstractLogger {

  /**
   * Recorded log calls, each ['level' => string, 'message' => string].
   *
   * @var array
   */
  protected array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, $message, array $context = []): void {
    $this->records[] = [
      'level' => (string) $level,
      'message' => $this->interpolate((string) $message, $context),
    ];
  }

  /**
   * Substitutes {placeholder} and @placeholder tokens.
   */
  protected function interpolate(string $message, array $context): string {
    foreach ($context as $key => $value) {
      if (is_scalar($value) || $value === NULL) {
        $message = str_replace(
          ['{' . $key . '}', '@' . ltrim($key, '@')],
          (string) $value,
          $message
        );
      }
    }
    return $message;
  }

  /**
   * Returns rendered messages logged at warning level.
   *
   * @return string[]
   *   The warning messages, in order.
   */
  public function getWarnings(): array {
    $out = [];
    foreach ($this->records as $record) {
      if ($record['level'] === 'warning') {
        $out[] = $record['message'];
      }
    }
    return $out;
  }

}
