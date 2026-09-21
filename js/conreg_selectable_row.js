/**
 * @file
 * Click-anywhere-on-the-row toggling for a table row with a checkbox.
 *
 * Generic - not tied to any one table - so any table can opt a row into
 * this by giving it the "conreg-table-row--selectable" class and a
 * ".checkbox-selectable" checkbox (the same class conreg_select_all.js
 * looks for). Originally built for, and still only used by, Member
 * Check-In's table.
 */

(function (Drupal, once) {
  Drupal.behaviors.conregSelectableRow = {
    attach(context) {
      // Highlight a row's background while its checkbox is selected.
      // Reacts to the checkbox's "change" event, so it stays in sync
      // however the checkbox was toggled: a direct click on it, a row
      // click (which fires a real click via checkbox.click(), see
      // below), or a "select all" checkbox (conreg_select_all.js
      // dispatches a "change" event on each checkbox it sets, precisely
      // so this listener catches it too).
      once(
        'conreg-selectable-row-highlight',
        '.conreg-table-row--selectable input.checkbox-selectable[type="checkbox"]',
        context,
      ).forEach((checkbox) => {
        const row = checkbox.closest('tr');
        if (!row) {
          return;
        }

        const sync = () => {
          row.classList.toggle('conreg-table-row--selected', checkbox.checked);
        };

        sync();
        checkbox.addEventListener('change', sync);
      });

      // Row click handler. Clicking anywhere on the row toggles its
      // checkbox. The closest() guard below leaves clicks on any
      // interactive element (including the checkbox and its label, and
      // any action column of buttons/links) to behave natively.
      once('conreg-selectable-row-click', '.conreg-table-row--selectable', context).forEach((row) => {
        row.addEventListener('click', (event) => {
          if (event.target.closest('a, button, input, label, select')) {
            return;
          }

          const checkbox = row.querySelector('input.checkbox-selectable[type="checkbox"]');
          if (!checkbox) {
            return;
          }

          // A real click (rather than manually flipping .checked and
          // dispatching a synthetic event) toggles the checkbox and
          // fires both "click" and "change" natively -
          // conreg_select_all.js syncs the "select all" checkbox on the
          // checkbox's "click" event, not "change", so anything less
          // than a real click leaves it stale.
          checkbox.click();
        });
      });
    },
  };
})(Drupal, once);
