(function (Drupal, once, drupalSettings) {
  'use strict';

  /**
   * Wires the "Preview" and "Test print" buttons on the Label Printing
   * Settings form. Preview sends the form's *current, unsaved* field
   * values (plus the "Test name" sample) to conreg_label_preview (see
   * LabelPreviewController::preview()) so an admin can iterate on
   * layout without saving each time. Test print re-generates that same
   * preview immediately before sending it, then queues exactly those
   * bytes as a real print job via conreg_label_test_print - so what
   * was just shown in the preview is exactly what prints, no
   * re-rendering happens server-side for the print step. Plain
   * fetch(), not Drupal's #ajax, since this only ever needs to update
   * one <img> and one status line.
   */
  Drupal.behaviors.conregLabelPreview = {
    attach: function (context) {
      once('conreg-label-preview', '#conreg-label-preview-button', context).forEach(function (previewButton) {
        const form = previewButton.closest('form');
        const image = document.getElementById('conreg-label-preview-image');
        const testPrintButton = document.getElementById('conreg-label-test-print-button');
        const printerSelect = document.getElementById('edit-test-print-printer');
        const status = document.getElementById('conreg-label-test-print-status');
        if (!form || !image) {
          return;
        }

        function buildFields() {
          const testNameField = form.querySelector('[name="test_name"]');
          return {
            badge_name: (testNameField && testNameField.value) || 'Jane Doe',
            member_number: 'M-4021',
            days_attending: 'Fri-Sun',
            badge_type: 'Adult',
          };
        }

        function buildPayload() {
          const fieldPositions = {};
          form.querySelectorAll('[name^="field_positions["]').forEach(function (select) {
            const match = select.name.match(/^field_positions\[(.+)\]$/);
            if (match) {
              fieldPositions[match[1]] = select.value;
            }
          });

          const labelSizeField = form.querySelector('[name="label_size"]');
          const nameLinesField = form.querySelector('[name="name_lines"]');

          return {
            label_size: labelSizeField ? labelSizeField.value : undefined,
            name_lines: nameLinesField ? parseInt(nameLinesField.value, 10) : undefined,
            field_positions: fieldPositions,
            fields: buildFields(),
          };
        }

        // Generates a fresh preview, updates the <img>, and resolves
        // with the data URI - shared by both buttons so "Test print"
        // always sends a preview that matches the form's current state.
        function generatePreview() {
          return fetch(drupalSettings.conreg.labelPreviewUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(buildPayload()),
          })
            .then(function (response) {
              return response.json();
            })
            .then(function (data) {
              if (data.image_data_uri) {
                image.src = data.image_data_uri;
                return data.image_data_uri;
              }
              throw new Error(data.error || 'Preview failed.');
            });
        }

        previewButton.addEventListener('click', function () {
          previewButton.disabled = true;
          generatePreview().finally(function () {
            previewButton.disabled = false;
          });
        });

        if (testPrintButton) {
          testPrintButton.addEventListener('click', function () {
            testPrintButton.disabled = true;
            if (status) {
              status.textContent = '';
            }

            generatePreview()
              .then(function (imageDataUri) {
                return fetch(drupalSettings.conreg.labelTestPrintUrl, {
                  method: 'POST',
                  headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify({
                    printer: printerSelect ? printerSelect.value : undefined,
                    image_data_uri: imageDataUri,
                    fields: buildFields(),
                  }),
                });
              })
              .then(function (response) {
                return response.json();
              })
              .then(function (data) {
                if (!status) {
                  return;
                }
                status.textContent = data.queued
                  ? Drupal.t('Test print queued (job #@id) - it will print next time that printer\'s agent polls.', {'@id': data.job_id})
                  : (data.error || Drupal.t('Test print failed.'));
              })
              .catch(function (error) {
                if (status) {
                  status.textContent = error.message || Drupal.t('Test print failed.');
                }
              })
              .finally(function () {
                testPrintButton.disabled = false;
              });
          });
        }
      });
    },
  };
})(Drupal, once, drupalSettings);
