<?php

namespace Drupal\user_profiles\Exception;

/**
 * Thrown when a user merge fails and the transaction has been rolled back.
 */
class UserMergeException extends \RuntimeException {
}
