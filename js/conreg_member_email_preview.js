(function ($, Drupal, once) {
  'use strict';

  // How long to wait after the admin stops typing before refreshing the
  // preview - long enough that normal typing doesn't trigger a stream of
  // AJAX requests, short enough that the preview still feels responsive.
  const PREVIEW_DEBOUNCE_MS = 5000;

  /**
   * Debounces live typing in the subject/body fields into the existing
   * #ajax('event': 'change') preview refresh.
   *
   * Drupal's #ajax only fires on a real 'change' event, which a plain
   * textfield/textarea only dispatches on blur - and a CKEditor-attached
   * textarea never dispatches it at all, since CKEditor only writes back
   * to the underlying textarea on specific triggers (e.g. form submit),
   * not on every edit. Listening for 'input' (subject/plain body) and
   * 'formUpdated' (fired by Drupal core's own editor.js on every
   * CKEditor change - see Drupal.editorAttach()) and re-dispatching a
   * debounced 'change' event lets the same #ajax binding refresh the
   * preview as the admin types, without a request per keystroke.
   */
  Drupal.behaviors.conregMemberEmailPreview = {
    attach(context) {
      once('conreg-member-email-preview', '.conreg-member-email-message', context).forEach((container) => {
        const subject = container.querySelector('input[type="text"]');
        if (subject) {
          subject.addEventListener('input', Drupal.debounce(() => {
            $(subject).trigger('change');
          }, PREVIEW_DEBOUNCE_MS));
        }

        const body = container.querySelector('textarea');
        if (body) {
          const triggerBodyChange = Drupal.debounce(() => {
            $(body).trigger('change');
          }, PREVIEW_DEBOUNCE_MS);
          body.addEventListener('input', triggerBodyChange);
          $(body).on('formUpdated', triggerBodyChange);
        }
      });
    },
  };
}(jQuery, Drupal, once));
