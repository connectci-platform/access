<?php

namespace Drupal\access_misc\Commands;

use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush commands for the access_misc module.
 *
 * @package Drupal\access_misc\Commands
 */
class AccessMiscCommands extends DrushCommands {

  /**
   * Generate a catalog of all notification emails in the system.
   *
   * Scans config YAML files, EmailBuilder plugins, WebformHandler plugins,
   * and PHP source for hook_mail / drupal_mail / ConstantContactApi usage,
   * then writes per-portal markdown files plus an index.md summary.
   *
   * @command access_misc:generate-notification-catalog
   * @aliases gen-notif-catalog
   * @option output-dir Directory to write markdown files into.
   * @option format Output format: json, yaml, or markdown.
   * @usage access_misc:generate-notification-catalog --output-dir=./notification-docs --format=markdown
   */
  public function generateNotificationCatalog(
    $options = [
      'output-dir' => './notification-docs',
      'format' => 'markdown',
    ]
  ) {
    $outputDir = rtrim($options['output-dir'], '/');
    $format = $options['format'];

    if (!in_array($format, ['json', 'yaml', 'markdown'])) {
      $this->io()->error("Invalid format '{$format}'. Use json, yaml, or markdown.");
      return;
    }

    $drupalRoot = \Drupal::root();
    $configDir = $drupalRoot . '/sites/default/config/default';
    $modulesDir = $drupalRoot . '/modules/custom/access';

    $notifications = [];
    $counts = [];

    // --- Source A: Content Moderation Notifications ---
    $cmFiles = glob($configDir . '/content_moderation_notifications.content_moderation_notification.*.yml');
    $counts['content_moderation'] = 0;
    foreach ($cmFiles as $file) {
      $data = Yaml::parseFile($file);
      if (!$data) {
        continue;
      }
      $id = $data['id'] ?? basename($file, '.yml');
      $transitions = array_keys($data['transitions'] ?? []);
      $roles = array_keys($data['roles'] ?? []);
      $portal = $this->portalFromKey($id);
      $record = [
        'name' => $data['label'] ?? $id,
        'portals' => [$portal],
        'trigger' => 'content moderation transition: ' . implode(', ', $transitions),
        'send_method' => 'email',
        'timing' => 'immediate',
        'recipient' => !empty($roles) ? 'Roles: ' . implode(', ', $roles) : ($data['author'] ? 'content author' : 'site mail'),
        'recipient_role' => !empty($roles) ? implode(', ', $roles) : 'any',
        'subject' => $data['subject'] ?? '',
        'body' => $this->cleanHtml($data['body']['value'] ?? ''),
        'edit_location' => 'config: ' . basename($file),
        'is_shared' => FALSE,
        'needs_review' => FALSE,
        '_source' => 'content_moderation',
      ];
      $notifications[] = $record;
      $counts['content_moderation']++;
    }

    // --- Source B: Symfony Mailer policies ---
    $mailerFiles = glob($configDir . '/symfony_mailer.mailer_policy.*.yml');
    $counts['symfony_mailer_policy'] = 0;
    foreach ($mailerFiles as $file) {
      $data = Yaml::parseFile($file);
      if (!$data) {
        continue;
      }
      $id = $data['id'] ?? basename($file, '.yml');
      // Skip the global fallback policy _.
      if ($id === '_') {
        continue;
      }
      // id format: "module.subtype"
      $parts = explode('.', $id, 2);
      $module = $parts[0];
      $subtype = $parts[1] ?? '';
      $portal = $this->portalFromKey($module);

      $subject = $data['configuration']['email_subject']['value'] ?? '';
      $bodyVal = $data['configuration']['email_body']['content']['value'] ?? '';
      $record = [
        'name' => $subtype ?: $id,
        'portals' => [$portal],
        'trigger' => 'user action / system event',
        'send_method' => 'email',
        'timing' => 'immediate',
        'recipient' => 'varies',
        'recipient_role' => 'any',
        'subject' => $subject,
        'body' => $this->cleanHtml($bodyVal),
        'edit_location' => 'config: ' . basename($file),
        'is_shared' => FALSE,
        'needs_review' => FALSE,
        '_source' => 'symfony_mailer_policy',
      ];
      $notifications[] = $record;
      $counts['symfony_mailer_policy']++;
    }

    // --- Source C: EmailBuilder plugins ---
    $ebFiles = glob($modulesDir . '/*/src/Plugin/EmailBuilder/*.php');
    $ebFiles = array_merge($ebFiles, glob($modulesDir . '/*/*/src/Plugin/EmailBuilder/*.php'));
    $counts['email_builder'] = 0;
    foreach ($ebFiles as $file) {
      $content = file_get_contents($file);
      // Extract @EmailBuilder annotation id and sub_types.
      if (!preg_match('/@EmailBuilder\s*\(.*?id\s*=\s*"([^"]+)"(.*?)\)/s', $content, $annMatch)) {
        continue;
      }
      $pluginId = $annMatch[1];
      $portal = $this->portalFromKey($pluginId);
      $subtypesBlock = $annMatch[2] ?? '';
      // Extract sub_types entries.
      preg_match_all('/"([^"]+)"\s*=\s*@Translation\("([^"]+)"\)', $subtypesBlock, $stMatches, PREG_SET_ORDER);
      if (empty($stMatches)) {
        // No sub_types — add one record for the plugin itself.
        $record = [
          'name' => $pluginId,
          'portals' => [$portal],
          'trigger' => 'user action / system event',
          'send_method' => 'email',
          'timing' => 'immediate',
          'recipient' => 'varies',
          'recipient_role' => 'any',
          'subject' => '',
          'body' => $this->classDocblock($content),
          'edit_location' => 'Module:' . basename($file),
          'is_shared' => FALSE,
          'needs_review' => FALSE,
          '_source' => 'email_builder',
        ];
        $notifications[] = $record;
        $counts['email_builder']++;
      }
      else {
        foreach ($stMatches as $st) {
          $record = [
            'name' => $st[2],
            'portals' => [$portal],
            'trigger' => 'user action / system event',
            'send_method' => 'email',
            'timing' => 'immediate',
            'recipient' => 'varies',
            'recipient_role' => 'any',
            'subject' => '',
            'body' => $st[2],
            'edit_location' => 'Module:' . basename(dirname(dirname(dirname(dirname($file))))) . ':' . basename($file),
            'is_shared' => FALSE,
            'needs_review' => FALSE,
            '_source' => 'email_builder',
          ];
          $notifications[] = $record;
          $counts['email_builder']++;
        }
      }
    }

    // --- Source D: Hook-based emails (regex scan) ---
    $counts['hook_mail'] = 0;
    $phpFiles = $this->findPhpFiles($modulesDir);
    foreach ($phpFiles as $phpFile) {
      $content = file_get_contents($phpFile);
      // Find hook_mail implementations.
      if (preg_match('/function\s+(\w+)_mail\s*\(\s*\$key/', $content, $m)) {
        $module = $m[1];
        $portal = $this->portalFromKey($module);
        $relPath = str_replace($drupalRoot . '/', '', $phpFile);
        // Find all $key conditions to enumerate mail keys.
        preg_match_all("/case\s+'([^']+)'/", $content, $cases);
        $keys = !empty($cases[1]) ? $cases[1] : ['(see file)'];
        foreach ($keys as $key) {
          $record = [
            'name' => "{$module}: {$key}",
            'portals' => [$portal],
            'trigger' => 'hook_mail',
            'send_method' => 'email',
            'timing' => 'immediate',
            'recipient' => 'varies',
            'recipient_role' => 'any',
            'subject' => '',
            'body' => "hook_mail key '{$key}' in {$module}",
            'edit_location' => $relPath,
            'is_shared' => FALSE,
            'needs_review' => TRUE,
            '_source' => 'hook_mail',
          ];
          $notifications[] = $record;
          $counts['hook_mail']++;
        }
      }
      // Find drupal_mail() / $mailManager->mail() calls.
      $mailCalls = [];
      preg_match_all('/\$mailManager->mail\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $content, $mm);
      if (!empty($mm[1])) {
        foreach ($mm[1] as $i => $mod) {
          $mailCalls[] = [$mod, $mm[2][$i]];
        }
      }
      preg_match_all('/drupal_mail\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $content, $dm);
      if (!empty($dm[1])) {
        foreach ($dm[1] as $i => $mod) {
          $mailCalls[] = [$mod, $dm[2][$i]];
        }
      }
      foreach ($mailCalls as $call) {
        [$mod, $key] = $call;
        $portal = $this->portalFromKey($mod);
        $relPath = str_replace($drupalRoot . '/', '', $phpFile);
        $record = [
          'name' => "{$mod}: {$key}",
          'portals' => [$portal],
          'trigger' => 'programmatic mail call',
          'send_method' => 'email',
          'timing' => 'immediate',
          'recipient' => 'varies',
          'recipient_role' => 'any',
          'subject' => '',
          'body' => "mail('{$mod}', '{$key}') call",
          'edit_location' => $relPath,
          'is_shared' => FALSE,
          'needs_review' => TRUE,
          '_source' => 'hook_mail',
        ];
        $notifications[] = $record;
        $counts['hook_mail']++;
      }
    }

    // --- Source E: Constant Contact campaigns ---
    $counts['constant_contact'] = 0;
    foreach ($phpFiles as $phpFile) {
      $content = file_get_contents($phpFile);
      if (!preg_match('/ConstantContactApi/', $content)) {
        continue;
      }
      // Look for emailCampaign or similar campaign method calls.
      preg_match_all('/\$\w+->([a-zA-Z]+Campaign[a-zA-Z]*)\s*\(/', $content, $ccm);
      if (!empty($ccm[1])) {
        $relPath = str_replace($drupalRoot . '/', '', $phpFile);
        $module = $this->moduleFromPath($phpFile);
        $portal = $this->portalFromKey($module);
        foreach (array_unique($ccm[1]) as $method) {
          $record = [
            'name' => "Constant Contact: {$method}",
            'portals' => [$portal],
            'trigger' => 'programmatic CC call',
            'send_method' => 'constant-contact',
            'timing' => 'immediate',
            'recipient' => 'CC list members',
            'recipient_role' => 'any',
            'subject' => '',
            'body' => "ConstantContactApi::{$method}() in " . basename($phpFile),
            'edit_location' => $relPath,
            'is_shared' => FALSE,
            'needs_review' => TRUE,
            '_source' => 'constant_contact',
          ];
          $notifications[] = $record;
          $counts['constant_contact']++;
        }
      }
      // Also flag files using ConstantContactApi that send emails (addContact, etc.).
      if (preg_match('/new ConstantContactApi/', $content)) {
        $relPath = str_replace($drupalRoot . '/', '', $phpFile);
        $module = $this->moduleFromPath($phpFile);
        $portal = $this->portalFromKey($module);
        // Check if already captured above.
        if (empty($ccm[1])) {
          $record = [
            'name' => 'Constant Contact usage in ' . basename($phpFile),
            'portals' => [$portal],
            'trigger' => 'programmatic CC call',
            'send_method' => 'constant-contact',
            'timing' => 'immediate',
            'recipient' => 'CC list members',
            'recipient_role' => 'any',
            'subject' => '',
            'body' => 'ConstantContactApi used in ' . basename($phpFile),
            'edit_location' => $relPath,
            'is_shared' => FALSE,
            'needs_review' => TRUE,
            '_source' => 'constant_contact',
          ];
          $notifications[] = $record;
          $counts['constant_contact']++;
        }
      }
    }

    // --- Source F: Webform email handlers (YAML) ---
    $webformFiles = glob($configDir . '/webform.webform.*.yml');
    $counts['webform_yaml'] = 0;
    foreach ($webformFiles as $file) {
      $data = Yaml::parseFile($file);
      if (!$data || !isset($data['handlers'])) {
        continue;
      }
      $webformId = $data['id'] ?? basename($file, '.yml');
      foreach ($data['handlers'] as $handlerKey => $handler) {
        $handlerPlugin = $handler['handler_id'] ?? ($handler['plugin'] ?? '');
        // Standard Drupal webform email handler uses handler_id "email"
        // or plugin "email".
        if ($handlerPlugin !== 'email' && !str_contains(strtolower($handlerPlugin), 'email')) {
          continue;
        }
        $portal = $this->portalFromKey($webformId);
        $settings = $handler['settings'] ?? [];
        $record = [
          'name' => ($handler['label'] ?? $handlerKey) . " ({$webformId})",
          'portals' => [$portal],
          'trigger' => 'webform submission',
          'send_method' => 'email',
          'timing' => 'immediate',
          'recipient' => $settings['to_mail'] ?? 'varies',
          'recipient_role' => 'any',
          'subject' => $settings['subject'] ?? '',
          'body' => 'Webform email handler on ' . $webformId,
          'edit_location' => 'config: ' . basename($file),
          'is_shared' => FALSE,
          'needs_review' => FALSE,
          '_source' => 'webform_yaml',
        ];
        $notifications[] = $record;
        $counts['webform_yaml']++;
      }
    }

    // --- Source G: Webform email handlers (PHP) ---
    $wfhFiles = glob($modulesDir . '/*/src/Plugin/WebformHandler/*.php');
    $wfhFiles = array_merge($wfhFiles, glob($modulesDir . '/*/*/src/Plugin/WebformHandler/*.php'));
    $counts['webform_php'] = 0;
    foreach ($wfhFiles as $file) {
      $content = file_get_contents($file);
      if (!preg_match('/@WebformHandler\s*\(.*?id\s*=\s*"([^"]+)".*?description\s*=\s*@Translation\("([^"]+)"\)/s', $content, $wm)) {
        // Try simpler match.
        if (!preg_match('/@WebformHandler\s*\(\s*id\s*=\s*"([^"]+)"/s', $content, $wm)) {
          $wm = [NULL, basename($file, '.php'), ''];
        }
      }
      $handlerId = $wm[1] ?? basename($file, '.php');
      $description = $wm[2] ?? '';
      $module = $this->moduleFromPath($file);
      $portal = $this->portalFromKey($module);
      $relPath = str_replace($drupalRoot . '/', '', $file);
      // Only include handlers that appear to send emails.
      if (!str_contains(strtolower($content), 'mail') && !str_contains(strtolower($handlerId), 'email')) {
        continue;
      }
      $record = [
        'name' => $description ?: $handlerId,
        'portals' => [$portal],
        'trigger' => 'webform submission',
        'send_method' => 'email',
        'timing' => 'immediate',
        'recipient' => 'varies',
        'recipient_role' => 'any',
        'subject' => '',
        'body' => $description ?: "WebformHandler '{$handlerId}'",
        'edit_location' => $relPath,
        'is_shared' => FALSE,
        'needs_review' => FALSE,
        '_source' => 'webform_php',
      ];
      $notifications[] = $record;
      $counts['webform_php']++;
    }

    // --- Deduplicate by name+portal ---
    $seen = [];
    $unique = [];
    foreach ($notifications as $n) {
      $key = $n['name'] . '|' . implode(',', $n['portals']);
      if (!isset($seen[$key])) {
        $seen[$key] = TRUE;
        $unique[] = $n;
      }
    }
    $notifications = $unique;

    // --- Mark is_shared for notifications appearing in 2+ portals ---
    $namePortals = [];
    foreach ($notifications as $n) {
      $namePortals[$n['name']][] = $n['portals'][0];
    }
    foreach ($notifications as &$n) {
      $ps = array_unique($namePortals[$n['name']] ?? []);
      if (count($ps) > 1) {
        $n['is_shared'] = TRUE;
        $n['portals'] = $ps;
      }
    }
    unset($n);

    // --- Group by portal ---
    $byPortal = [];
    foreach ($notifications as $n) {
      foreach ($n['portals'] as $portal) {
        $byPortal[$portal][] = $n;
      }
    }

    // --- Output ---
    if (!is_dir($outputDir)) {
      mkdir($outputDir, 0755, TRUE);
    }

    if ($format === 'json') {
      file_put_contents($outputDir . '/notifications.json', json_encode($notifications, JSON_PRETTY_PRINT));
      $this->io()->success("Wrote {$outputDir}/notifications.json");
    }
    elseif ($format === 'yaml') {
      file_put_contents($outputDir . '/notifications.yml', Yaml::dump($notifications, 4, 2));
      $this->io()->success("Wrote {$outputDir}/notifications.yml");
    }
    else {
      // Markdown output.
      $this->writeMarkdownFiles($outputDir, $byPortal, $notifications);
    }

    // --- Summary counts ---
    $this->output()->writeln('');
    $this->output()->writeln('Notification catalog complete. Source counts:');
    foreach ($counts as $src => $cnt) {
      $this->output()->writeln(sprintf('  %-30s %d', $src . ':', $cnt));
    }
    $this->output()->writeln(sprintf('  %-30s %d', 'TOTAL (unique):', count($notifications)));
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /**
   * Derive a portal name from a config key prefix or module name.
   */
  private function portalFromKey(string $key): string {
    $lower = strtolower($key);
    if (str_starts_with($lower, 'ccmnet') || $lower === 'ccmnet') {
      return 'ccmnet';
    }
    if (str_starts_with($lower, 'ondemand') || str_contains($lower, 'open_ondemand') || str_contains($lower, 'openondemand') || str_starts_with($lower, 'appverse')) {
      return 'open-ondemand';
    }
    if (str_starts_with($lower, 'affinitygroup') || str_starts_with($lower, 'access_affinitygroup')) {
      return 'campus-champions';
    }
    if (str_contains($lower, 'pascience') || str_starts_with($lower, 'access_misc_project')) {
      return 'pascience';
    }
    if (str_starts_with($lower, 'access_misc') || str_starts_with($lower, 'access_news') || str_starts_with($lower, 'access_cilink')) {
      return 'access-support';
    }
    // Default fallback.
    return 'access-support';
  }

  /**
   * Derive module name from a file path.
   */
  private function moduleFromPath(string $path): string {
    // Try to find module name from path segment after modules/custom/access.
    if (preg_match('#/modules/custom/access/(?:modules/)?([^/]+)#', $path, $m)) {
      return $m[1];
    }
    return 'access';
  }

  /**
   * Clean HTML body text by stripping tags and normalizing whitespace.
   */
  private function cleanHtml(string $html): string {
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', trim($text));
    return $text;
  }

  /**
   * Extract the class-level docblock summary from PHP source.
   */
  private function classDocblock(string $content): string {
    if (preg_match('#/\*\*\s+\*\s+(.*?)\s+\*\s+@#s', $content, $m)) {
      return trim(preg_replace('/\s+/', ' ', $m[1]));
    }
    return '';
  }

  /**
   * Recursively find all .php files under a directory.
   */
  private function findPhpFiles(string $dir): array {
    $files = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
      if ($file->getExtension() === 'php') {
        $files[] = $file->getPathname();
      }
    }
    return $files;
  }

  /**
   * Human-readable portal label.
   */
  private function portalLabel(string $portal): string {
    $labels = [
      'access-support' => 'ACCESS Support',
      'campus-champions' => 'Campus Champions',
      'ccmnet' => 'CCMNet',
      'open-ondemand' => 'Open OnDemand',
      'pascience' => 'PA Science DMZ',
      'shared' => 'Shared / Multi-portal',
    ];
    return $labels[$portal] ?? ucwords(str_replace('-', ' ', $portal));
  }

  /**
   * Write per-portal markdown files and index.md.
   */
  private function writeMarkdownFiles(string $outputDir, array $byPortal, array $allNotifications): void {
    $header = "<!-- auto-generated, do not hand-edit -->\n\n";

    // Per-portal files.
    foreach ($byPortal as $portal => $records) {
      $label = $this->portalLabel($portal);
      $md = $header;
      $md .= "# {$label} Notifications\n\n";
      $md .= "| Name | Trigger | Send Method | Subject | Recipient | Needs Review |\n";
      $md .= "|------|---------|-------------|---------|-----------|-------------|\n";
      foreach ($records as $r) {
        $md .= sprintf(
          "| %s | %s | %s | %s | %s | %s |\n",
          $this->mdCell($r['name']),
          $this->mdCell($r['trigger']),
          $this->mdCell($r['send_method']),
          $this->mdCell($r['subject']),
          $this->mdCell($r['recipient']),
          $r['needs_review'] ? '⚠ yes' : 'no'
        );
      }
      $md .= "\n## Details\n\n";
      foreach ($records as $r) {
        $md .= "### " . $this->mdEscape($r['name']) . "\n\n";
        $md .= "| Field | Value |\n|-------|-------|\n";
        $fields = [
          'Portals' => implode(', ', $r['portals']),
          'Trigger' => $r['trigger'],
          'Send Method' => $r['send_method'],
          'Timing' => $r['timing'],
          'Recipient' => $r['recipient'],
          'Recipient Role' => $r['recipient_role'],
          'Subject' => $r['subject'],
          'Body' => $r['body'],
          'Edit Location' => $r['edit_location'],
          'Is Shared' => $r['is_shared'] ? 'yes' : 'no',
          'Needs Review' => $r['needs_review'] ? '⚠ yes' : 'no',
        ];
        foreach ($fields as $k => $v) {
          $md .= "| **{$k}** | " . $this->mdCell($v) . " |\n";
        }
        $md .= "\n";
      }
      $filename = $outputDir . '/' . $portal . '.md';
      file_put_contents($filename, $md);
      $this->output()->writeln("Wrote {$filename}");
    }

    // index.md.
    $md = $header;
    $md .= "# Notification Catalog — All Portals\n\n";
    $md .= "| Name | Portal(s) | Trigger | Send Method | Subject | Needs Review |\n";
    $md .= "|------|-----------|---------|-------------|---------|-------------|\n";
    foreach ($allNotifications as $r) {
      $md .= sprintf(
        "| %s | %s | %s | %s | %s | %s |\n",
        $this->mdCell($r['name']),
        $this->mdCell(implode(', ', $r['portals'])),
        $this->mdCell($r['trigger']),
        $this->mdCell($r['send_method']),
        $this->mdCell($r['subject']),
        $r['needs_review'] ? '⚠ yes' : 'no'
      );
    }
    $indexFile = $outputDir . '/index.md';
    file_put_contents($indexFile, $md);
    $this->output()->writeln("Wrote {$indexFile}");
  }

  /**
   * Escape pipe characters for markdown table cells.
   */
  private function mdCell(string $value): string {
    return str_replace(['|', "\n", "\r"], ['\|', ' ', ''], $value);
  }

  /**
   * Escape markdown heading characters.
   */
  private function mdEscape(string $value): string {
    return str_replace(['#', '[', ']'], ['\\#', '\\[', '\\]'], $value);
  }

}
