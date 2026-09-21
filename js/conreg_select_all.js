(function ($) {
  'use strict';

  Drupal.behaviors.disableOnClick = {
    attach: function (context, settings) {

      // Function to check "select all" if all checkboxes are selected.
      // An empty list (e.g. Member Check-In before a search has been
      // run) must not count as "all selected" - otherwise "select all"
      // renders checked with nothing actually selected, and stays
      // checked-but-wrong if a later search adds rows nobody explicitly
      // selected.
      function checkAllSelected() {
        const checkboxes = $('.checkbox-selectable');
        let allSelected = checkboxes.length > 0;
        checkboxes.each(function(check) {
          if (!this.checked) allSelected = false;
        });
        $('.select-all').prop('checked', allSelected);
      }

      // Check initial state (if all checkboxes checked, check all should start checked).
      checkAllSelected();

      // Function to check all checkboxes when "select all" checked. Only
      // touches checkboxes whose state is actually changing, dispatching a
      // real "change" event on each so other listeners (e.g. check-in row
      // highlighting) stay in sync - unlike jQuery's .prop(), a plain
      // property assignment fires no events on its own.
      $('.select-all', context).on('click', function(event) {
        $('.checkbox-selectable').each(function() {
          if (this.checked !== event.target.checked) {
            this.checked = event.target.checked;
            this.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
      });

      // Function to update "select all" when checkboxes change. Set to checked if all checkboxes checked, otherwise unchecked.
      $('.checkbox-selectable').on('click', function() {
        checkAllSelected();
      });

    }
  };

}(jQuery));