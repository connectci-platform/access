<?php

declare(strict_types=1);

namespace Drupal\Tests\access_events\Kernel;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;

/**
 * Required radio groups on the series form are flagged on the field.
 *
 * The series form used to switch #required off on its Recur Type and Event
 * Type radios, so a missing value only surfaced as an unlabeled "This value
 * should not be null." in the summary at the top, with nothing inline.
 *
 * Exercises _access_events_require_radio_groups() and
 * access_events_preprocess_fieldset() on a stand-in form carrying the same
 * widget shape (see AllocationGrantWidgetTest for why the full eventseries
 * form is not built here). The wiring into the series form is one call in
 * access_events_form_alter().
 *
 * @group access_events
 */
class EventSeriesFormRequiredTest extends EventKernelTestBase {

  /**
   * Leaving both groups empty flags each one on the field, not just on top.
   */
  public function testMissingValuesAreFlaggedOnTheField(): void {
    [$form, $formState] = $this->submit([]);

    $errors = $formState->getErrors();
    $this->assertSame('Event Type field is required.', (string) ($errors['field_event_type'] ?? ''));
    $this->assertSame('Recur Type field is required.', (string) ($errors['recur_type'] ?? ''));

    foreach (['field_event_type', 'recur_type'] as $fieldName) {
      $widget = $form[$fieldName]['widget'];
      $this->assertTrue($widget['#required'], "$fieldName is required again.");
      $this->assertNotEmpty($widget['#errors'], "$fieldName carries its error.");
    }

    $html = $this->renderWidget($form, 'field_event_type');
    $fieldset = $this->fieldsetTag($html);
    $this->assertStringContainsString('role="radiogroup"', $fieldset);
    $this->assertStringContainsString('aria-required="true"', $fieldset);
    $this->assertStringContainsString('aria-invalid="true"', $fieldset);
    $this->assertStringNotContainsString('required="required"', $fieldset);
    $errorId = $this->fieldsetId($fieldset) . '--error';
    $this->assertStringContainsString('aria-describedby="' . $errorId . '"', $fieldset);
    // The message is printed inside the fieldset, under the id the group
    // points at.
    $this->assertStringContainsString('<span id="' . $errorId . '">Event Type field is required.</span>', $html);
    // Each radio is still marked invalid itself.
    $this->assertSame(2, preg_match_all('/<input[^>]*type="radio"[^>]*aria-invalid="true"/', $html));

    // An existing description reference is kept alongside the error.
    $fieldset = $this->fieldsetTag($this->renderWidget($form, 'recur_type'));
    $fieldsetId = $this->fieldsetId($fieldset);
    $this->assertStringContainsString('aria-describedby="' . $fieldsetId . '--description ' . $fieldsetId . '--error"', $fieldset);
  }

  /**
   * A valid submission passes and renders no error reference.
   */
  public function testValidValuesPass(): void {
    [$form, $formState] = $this->submit([
      'field_event_type' => 'Training',
      'recur_type' => 'custom',
      'field_skill_level' => 'Beginner',
    ]);

    $this->assertSame([], $formState->getErrors());

    $fieldset = $this->fieldsetTag($this->renderWidget($form, 'field_event_type'));
    $this->assertStringContainsString('role="radiogroup"', $fieldset);
    $this->assertStringContainsString('aria-required="true"', $fieldset);
    $this->assertStringNotContainsString('required="required"', $fieldset);
    $this->assertStringNotContainsString('aria-invalid', $fieldset);
    $this->assertStringNotContainsString('aria-describedby', $fieldset);
  }

  /**
   * Radio groups that were not opted in keep core's fieldset markup.
   */
  public function testUnflaggedRadiosAreUntouched(): void {
    [$form] = $this->submit([]);

    $fieldset = $this->fieldsetTag($this->renderWidget($form, 'field_skill_level'));
    $this->assertStringNotContainsString('role="radiogroup"', $fieldset);
    $this->assertStringContainsString('required="required"', $fieldset);
  }

  /**
   * Submits the stand-in series form with the given values.
   *
   * @return array
   *   The built form (with #errors set) and its form state.
   */
  private function submit(array $values): array {
    $formObject = new class() extends FormBase {

      /**
       * {@inheritdoc}
       */
      public function getFormId() {
        return 'access_events_required_radios_test_form';
      }

      /**
       * {@inheritdoc}
       */
      public function buildForm(array $form, FormStateInterface $form_state) {
        // Same shape as an options_buttons widget, with #required switched
        // off the way the series form used to.
        $radios = [
          'field_event_type' => [
            'Event Type',
            ['Conference' => 'Conference', 'Training' => 'Training'],
            NULL,
          ],
          'recur_type' => [
            'Recur Type',
            ['weekly_recurring_date' => 'Weekly Event', 'custom' => 'Custom Event'],
            'Select the type of recurrence.',
          ],
          'field_skill_level' => [
            'Skill Level',
            ['Beginner' => 'Beginner'],
            NULL,
          ],
        ];
        foreach ($radios as $fieldName => [$title, $options, $description]) {
          $form[$fieldName]['widget'] = [
            '#type' => 'radios',
            '#title' => $title,
            '#options' => $options,
            '#description' => $description,
            '#required' => $fieldName === 'field_skill_level',
            '#parents' => [$fieldName],
          ];
        }
        _access_events_require_radio_groups($form, ['recur_type', 'field_event_type']);
        $form['actions']['submit'] = ['#type' => 'submit', '#value' => 'Save'];
        return $form;
      }

      /**
       * {@inheritdoc}
       */
      public function submitForm(array &$form, FormStateInterface $form_state) {
      }

    };

    $formState = (new FormState())->setValues($values + ['op' => 'Save']);
    \Drupal::formBuilder()->submitForm($formObject, $formState);
    return [$formState->getCompleteForm(), $formState];
  }

  /**
   * Renders one widget of the submitted form.
   */
  private function renderWidget(array $form, string $fieldName): string {
    $element = $form[$fieldName]['widget'];
    return (string) \Drupal::service('renderer')->renderInIsolation($element);
  }

  /**
   * Returns the id attribute of an opening <fieldset> tag.
   *
   * Ids are deduplicated per request, so they are read rather than assumed.
   */
  private function fieldsetId(string $fieldset): string {
    $this->assertSame(1, preg_match('/ id="([^"]+)"/', $fieldset, $matches), 'The fieldset has an id.');
    return $matches[1];
  }

  /**
   * Returns the opening <fieldset> tag from rendered markup.
   */
  private function fieldsetTag(string $html): string {
    $this->assertSame(1, preg_match('/<fieldset[^>]*>/', $html, $matches), 'A fieldset is rendered.');
    return $matches[0];
  }

}
