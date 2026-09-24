<?php

declare(strict_types=1);

namespace Drupal\Tests\access\Traits;

/**
 * Registers a stub domain.negotiator for kernel tests.
 *
 * Shared by access_news and access_events DomainGuardTest, which both need
 * to simulate an active (or absent) domain without enabling the domain
 * module; see D8-2848.
 */
trait ActiveDomainStubTrait {

  /**
   * Registers a stub domain negotiator returning a fixed active domain.
   *
   * @param string|null $id
   *   The active domain ID the stub negotiator should return, or NULL to
   *   stub a negotiator with no active domain.
   */
  private function stubActiveDomain(?string $id): void {
    if ($id === NULL) {
      $negotiator = new class {

        /**
         * Returns no active domain.
         */
        public function getActiveDomain(): ?object {
          return NULL;
        }

      };
      \Drupal::getContainer()->set('domain.negotiator', $negotiator);
      return;
    }

    $domain = new class($id) {

      public function __construct(private string $id) {}

      /**
       * Returns the stubbed domain ID.
       */
      public function id(): string {
        return $this->id;
      }

    };
    $negotiator = new class($domain) {

      public function __construct(private object $domain) {}

      /**
       * Returns the stubbed active domain.
       */
      public function getActiveDomain(): object {
        return $this->domain;
      }

    };
    \Drupal::getContainer()->set('domain.negotiator', $negotiator);
  }

}
