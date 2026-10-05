<?php

namespace Drupal\Tests\access_misc\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests exposed input normalization for the PA Science submissions queue.
 *
 * An empty multi-select sends no query parameter, so "Apply with nothing
 * selected" is indistinguishable from a first load and the filter default
 * (Declined) is re-applied. The exposed form adds a "ps" marker to every
 * submission, and the helper turns marker-without-status into an explicit
 * empty selection.
 *
 * access_misc is not enabled here: its .info.yml dependency chain is large
 * and unrelated, and KernelTestBase::enableModules() does not resolve
 * dependencies. The module file is loaded directly to reach the helper.
 *
 * @group access_misc
 */
class ProjectSubmissionsStatusFilterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/access_misc.module';
  }

  /**
   * First load (no input) is unchanged so the default applies.
   */
  public function testNoInputKeepsDefault(): void {
    $this->assertSame([], _access_misc_project_submissions_normalize_input([]));
    $input = ['sort_by' => 'created'];
    $this->assertSame($input, _access_misc_project_submissions_normalize_input($input));
  }

  /**
   * Marker without a status selection clears the status filter.
   */
  public function testMarkerWithoutStatusClearsFilter(): void {
    $result = _access_misc_project_submissions_normalize_input(['ps' => '1']);
    $this->assertSame([], $result['webform_submission_value_1']);
    $this->assertSame('1', $result['ps']);
  }

  /**
   * Marker with a status selection is left unchanged.
   */
  public function testMarkerWithStatusUnchanged(): void {
    $input = ['ps' => '1', 'webform_submission_value_1' => ['Recruiting']];
    $this->assertSame($input, _access_misc_project_submissions_normalize_input($input));
  }

}
