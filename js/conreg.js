/**
 * @file
 * Simple Convention Registration.
 */

(function ($, Drupal, once) {
  // Track focus for restoration after AJAX.
  var focusedRadioName = null;
  var focusedRadioValue = null;

  // Timeout for delayed visual feedback.
  var processingVisualTimeout = null;

  // Function to restore focus to a radio button.
  function restoreFocus() {
    if (focusedRadioName) {
      var selector = 'input[type="radio"][name="' + CSS.escape(focusedRadioName) + '"][value="' + CSS.escape(focusedRadioValue) + '"]';
      var radio = document.querySelector(selector);
      if (radio) {
        radio.focus();
      }
      focusedRadioName = null;
      focusedRadioValue = null;
    }
  }

  // Function to update card selection visual state.
  function updateCardSelection(card) {
    var group = card.closest('.member-type-cards__options');
    if (group) {
      group.querySelectorAll('.member-type-card').forEach(function(c) {
        c.classList.remove('member-type-card--selected');
      });
    }
    card.classList.add('member-type-card--selected');
  }

  // Function to show processing indicator on a card (called after delay).
  function showProcessingVisual(card) {
    var indicator = document.createElement('span');
    indicator.className = 'member-type-card__processing';
    indicator.textContent = Drupal.t('Processing...');
    var nameEl = card.querySelector('.member-type-card__name');
    if (nameEl) {
      nameEl.appendChild(indicator);
    }
    // Add visual processing state to cards.
    var group = card.closest('.member-type-cards__options');
    if (group) {
      group.querySelectorAll('.member-type-card').forEach(function(c) {
        c.classList.add('member-type-card--processing');
      });
    }
  }

  // Function to schedule delayed visual feedback.
  function scheduleProcessingVisual(card) {
    // Cancel any existing timeout.
    cancelProcessingVisual();
    // Schedule visual feedback after 200ms.
    processingVisualTimeout = setTimeout(function() {
      showProcessingVisual(card);
      processingVisualTimeout = null;
    }, 200);
  }

  // Function to cancel pending visual feedback.
  function cancelProcessingVisual() {
    if (processingVisualTimeout) {
      clearTimeout(processingVisualTimeout);
      processingVisualTimeout = null;
    }
  }

  // Function to hide processing indicator and cancel any pending visual feedback.
  function hideProcessing() {
    cancelProcessingVisual();
    document.querySelectorAll('.member-type-card__processing').forEach(function(el) {
      el.remove();
    });
    document.querySelectorAll('.member-type-card--processing').forEach(function(el) {
      el.classList.remove('member-type-card--processing');
    });
  }

  // Function to disable all radio buttons in a member type cards group.
  function disableCardRadios(card) {
    var group = card.closest('.member-type-cards__options');
    if (group) {
      group.querySelectorAll('.member-type-card input[type="radio"]').forEach(function(radio) {
        radio.disabled = true;
      });
    }
  }

  // Function to re-enable all radio buttons after AJAX completes.
  function enableAllCardRadios() {
    document.querySelectorAll('.member-type-cards__options').forEach(function(group) {
      group.querySelectorAll('.member-type-card input[type="radio"]').forEach(function(radio) {
        // Only re-enable if not permanently disabled (e.g., not available for member 1).
        var card = radio.closest('.member-type-card');
        if (card && !card.classList.contains('member-type-card--disabled')) {
          radio.disabled = false;
        }
      });
    });
  }

  // Restore focus after AJAX completes - use multiple strategies to handle caching.
  $(document).on('ajaxComplete', function(event, xhr, settings) {
    hideProcessing();
    enableAllCardRadios();
    // Use requestAnimationFrame to ensure DOM has been updated.
    requestAnimationFrame(function() {
      restoreFocus();
    });
  });

  // Also listen for Drupal's AJAX command completion.
  $(document).on('drupalAjaxComplete', function() {
    hideProcessing();
    enableAllCardRadios();
    requestAnimationFrame(function() {
      restoreFocus();
    });
  });

  Drupal.behaviors.simple_conreg = {
    attach: function (context, settings) {
      // Restore focus if we have stored focus info (handles AJAX refresh).
      if (focusedRadioName && context !== document) {
        requestAnimationFrame(function() {
          restoreFocus();
        });
      }

      // Member type card click handler.
      // Clicking anywhere on the card selects the radio and triggers AJAX.
      once('member-type-card', '.member-type-card', context).forEach(function(card) {
        // Make links in cards open in new tabs.
        card.querySelectorAll('.member-type-card__description a').forEach(function(link) {
          link.setAttribute('target', '_blank');
          link.setAttribute('rel', 'noopener noreferrer');
        });

        card.addEventListener('click', function(event) {
          // Don't handle clicks on links - let them navigate normally.
          if (event.target.closest('a')) {
            return;
          }

          // Don't handle disabled cards.
          if (card.classList.contains('member-type-card--disabled')) {
            return;
          }

          // Find the radio input inside the card (rendered by Drupal).
          var input = card.querySelector('input[type="radio"]');
          if (!input || input.disabled) {
            return;
          }

          // Don't handle if clicking directly on the input.
          if (event.target === input) {
            return;
          }

          // Store focus info for restoration after any AJAX.
          focusedRadioName = input.name;
          focusedRadioValue = input.value;

          // Select the radio if not already selected.
          if (!input.checked) {
            input.checked = true;
            updateCardSelection(card);

            // Trigger change event for AJAX.
            $(input).trigger('change');

            // Defer disabling until after event handlers complete to not interfere with AJAX.
            setTimeout(function() {
              disableCardRadios(card);
              scheduleProcessingVisual(card);
            }, 0);
          }
          else {
            // No AJAX will happen, so focus immediately.
            input.focus();
            focusedRadioName = null;
            focusedRadioValue = null;
          }
        });
      });

      // Handle direct input changes (e.g., keyboard navigation).
      once('member-type-card-radio', '.member-type-card input[type="radio"]', context).forEach(function(input) {
        input.addEventListener('change', function() {
          var card = input.closest('.member-type-card');
          if (!card) {
            return;
          }
          updateCardSelection(card);

          // Store focus info for restoration after AJAX.
          focusedRadioName = input.name;
          focusedRadioValue = input.value;

          // Defer disabling until after event handlers complete to not interfere with AJAX.
          setTimeout(function() {
            disableCardRadios(card);
            scheduleProcessingVisual(card);
          }, 0);
        });

        // Sync visual state with actual radio state (e.g., after browser back/forward).
        if (input.checked) {
          var card = input.closest('.member-type-card');
          if (card) {
            updateCardSelection(card);
          }
        }
      });

      $('.edit-members-first-name,.edit-members-last-name', context).on('input',function(event) {
        var reg=/^([a-zA-Z0-9]+\-){3}/
        var base=reg.exec(event.target.id);
        var max_length = drupalSettings.conreg.badge_name_max;
        var first_name=$("#" + base[0] + "first-name").val().trim();
        var last_name=$("#" + base[0] + "last-name").val().trim();
        var name=first_name.concat(" ", last_name);
        var name_last=last_name.concat(", ", first_name);
        $("." + base[0] + "badge-name-option[value='N'] + label").text(name.substring(0, max_length));
        $("." + base[0] + "badge-name-option[value='F'] + label").text(first_name.substring(0, max_length));
        $("." + base[0] + "badge-name-option[value='L'] + label").text(name_last.substring(0, max_length));
      });

      // Loop through badge name option fields to determine whether to show "custom" badge name.
      $('.edit-members-badge-name-option').each(function(i, obj) {
        showBadgeNameOther(obj);
      });

      // If "other" badge name option selected, make custom badge name field visible, otherwise hide.
      $('.edit-members-badge-name-option').change(function(event) {
        showBadgeNameOther(event.currentTarget);
      });

      // If free amount entered,
      $(".edit-free-amt", context).on('input',function(event) {
        const regex = /member[0-9]+/g
        const found = event.target.id.match(regex);
        if (found) {
          var memberTotal = Number($("#edit-"+found[0]+"-price-minus-free-amt").val());
          memberTotal += Number(event.target.value);
          $("#"+found[0]+"-value").text(memberTotal.toFixed(2));
        }
        var total = Number($("#edit-total-minus-free-amt").val());
        $(".edit-free-amt").each(function(index) {
          total += Number($(this).val());
        });
        $("#total-value").text(total.toFixed(2));
        if (total == 0) {
          $("#edit-payment-submit").val(drupalSettings.submit.free);
        }
        else {
          $("#edit-payment-submit").val(drupalSettings.submit.payment);
        }
      });
    }
  };
})(jQuery, Drupal, once);

function showBadgeNameOther(obj)
{
  if (obj.checked) {
    var badgeOther = obj.parentNode.parentNode.parentNode.parentNode.parentNode.querySelector(".edit-members-badge-name-container")
    if (obj.value == 'O') {
      badgeOther.style.display = "block";
      badgeOther.querySelector(".edit-members-badge-name-other").required = true;
      badgeOther.querySelector("label").classList.add("form-required");
    }
    else {
      badgeOther.style.display = "none";
      badgeOther.querySelector(".edit-members-badge-name-other").required = false;
      badgeOther.querySelector("label").classList.remove("form-required");
    }
  }
}

