<?php

declare(strict_types=1);

namespace Drupal\conreg\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\easy_email\Form\EasyEmailTypeForm;
use Drupal\filter\Entity\FilterFormat;

/**
 * Warns when an Easy Email template's format would strip member-details.
 *
 * [conreg:member-details] renders as an HTML <table> (see
 * MemberDetailsFormatter) - a restrictive text format's "Limit allowed
 * HTML tags" filter can silently strip that table down to unformatted
 * run-on text if it doesn't allow table/tr/td/th. This exact bug bit a
 * hand-authored template that predated the fix migrating conreg's own
 * templates to the unrestricted full_html format - this validation
 * catches it at template-edit time instead of only being discovered when
 * a sent email looks wrong.
 */
class ConregEasyEmailTemplateValidationHooks {
  use StringTranslationTrait;

  /**
   * Tags [conreg:member-details]'s table markup needs to survive intact.
   */
  private const REQUIRED_TAGS = ['table', 'tr', 'td', 'th'];

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (!$form_state->getFormObject() instanceof EasyEmailTypeForm) {
      return;
    }
    $form['#validate'][] = [static::class, 'validateMemberDetailsFormat'];
  }

  /**
   * Form #validate callback warning about a table-hostile format.
   */
  public static function validateMemberDetailsFormat(array &$form, FormStateInterface $form_state): void {
    $bodyHtml = $form_state->getValue(['bodyHtml', 'value']) ?? '';
    if (!str_contains($bodyHtml, '[conreg:member-details]')) {
      return;
    }

    $formatId = $form_state->getValue(['bodyHtml', 'format']);
    $format = $formatId ? FilterFormat::load($formatId) : NULL;
    if (!$format) {
      return;
    }

    $restrictions = $format->getHtmlRestrictions();
    if ($restrictions === FALSE) {
      // No HTML-restricting filter at all (e.g. Full HTML) - safe.
      return;
    }

    // Note: an 'allowed' entry keyed '*' does not mean "all tags allowed" -
    // it lists global *attributes* (style, lang, dir, ...) permitted on
    // whichever tags are otherwise allowed, so it's not a wildcard tag
    // check here.
    $allowed = $restrictions['allowed'] ?? [];
    $missing = array_values(array_filter(
      self::REQUIRED_TAGS,
      fn (string $tag) => !array_key_exists($tag, $allowed),
    ));
    if ($missing) {
      \Drupal::messenger()->addWarning(t('This template uses [conreg:member-details], which renders as an HTML table, but the "@format" format does not allow the following tag(s): @tags. The table will be stripped down to plain, unformatted text when this email is sent. Use a format with no "Limit allowed HTML tags" restriction (e.g. Full HTML), or add these tags to that filter\'s allowed HTML.', [
        '@format' => $format->label(),
        '@tags' => implode(', ', $missing),
      ]));
    }
  }

}
