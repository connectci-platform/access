<?php

/**
 * @file
 * Generate a report of new User accounts per month.
 *
 * Written by Shawn Rice (@zooley)
 */

$now = new \DateTime('now');
$users = [];

$startYear = 2022;
$endYear = $now->format('Y');

$startMonth = 9;
$endMonth = $now->format('m');

for ($y = $startYear; $y <= $endYear; $y++) {
  $m = $startMonth;
  $m = ($y > $startYear) ? 1 : $m;

  $limit = 12;
  $limit = ($y == $endYear) ? $endMonth : $limit;

  for ($m; $m <= $limit; $m++) {
    $mm = $m < 10 ? '0' . $m : $m;

    $start = (new \DateTime($y . '-' . $mm . '-01'));

    $end = clone $start;
    $end->modify('+1 month');

    $users[$y . '-' . $mm] = \Drupal::database()->select('users_field_data', 'n')->fields('n', ['uid'])
      ->condition('created', $start->getTimestamp(), '>=')
      ->condition('created', $end->getTimestamp(), '<')
      ->countQuery()
      ->execute()
      ->fetchField();
    var_dump($users);
  }
}
