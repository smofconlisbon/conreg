/**
 * @file
 * ConReg behavior - handle badge name and pricing updates.
 */

(function (Drupal, once) {
  // Track focus for restoration after AJAX.
  let focusedRadioName = null;
  let focusedRadioValue = null;

  // Timeout for delayed visual feedback.
  let processingVisualTimeout = null;

  // Function to restore focus to a card after AJAX.
  function restoreFocus() {
    if (focusedRadioName) {
      const selector = 'input[type="radio"][name="' + CSS.escape(focusedRadioName) + '"][value="' + CSS.escape(focusedRadioValue) + '"]';
      const input = document.querySelector(selector);
      const card = input?.closest('.member-type-card');
      if (card) {
        card.focus();
      }
      focusedRadioName = null;
      focusedRadioValue = null;
    }
  }

  // Function to return the enabled cards in a group, in DOM order.
  function getEnabledCards(group) {
    return Array.from(group.querySelectorAll('.member-type-card')).filter(
      (c) => !c.classList.contains('member-type-card--disabled'),
    );
  }

  // Function to update roving tabindex so only one card in the group is a tab stop.
  function updateTabIndexes(group, activeCard) {
    const enabledCards = getEnabledCards(group);
    const target = enabledCards.includes(activeCard) ? activeCard : enabledCards[0];
    enabledCards.forEach((c) => {
      c.setAttribute('tabindex', c === target ? '0' : '-1');
    });
  }

  // Function to update card selection visual and ARIA state.
  function updateCardSelection(card) {
    const group = card.closest('.member-type-cards__options');
    if (group) {
      group.querySelectorAll('.member-type-card').forEach((c) => {
        c.classList.remove('member-type-card--selected');
        c.setAttribute('aria-checked', 'false');
      });
      updateTabIndexes(group, card);
    }
    card.classList.add('member-type-card--selected');
    card.setAttribute('aria-checked', 'true');
  }

  // Function to select a card: checks its radio and triggers the change/AJAX flow.
  function selectCard(card) {
    if (card.classList.contains('member-type-card--disabled')) {
      return;
    }

    const input = card.querySelector('input[type="radio"]');
    if (!input || input.disabled) {
      return;
    }

    // Store focus info for restoration after any AJAX.
    focusedRadioName = input.name;
    focusedRadioValue = input.value;

    if (!input.checked) {
      input.checked = true;
      updateCardSelection(card);

      // Trigger change event for AJAX.
      input.dispatchEvent(new Event('change', { bubbles: true }));

      // Defer disabling until after event handlers complete to not interfere with AJAX.
      setTimeout(() => {
        disableCardRadios(card);
        scheduleProcessingVisual(card);
      }, 0);
    }
    else {
      // No AJAX will happen, so focus immediately.
      card.focus();
      focusedRadioName = null;
      focusedRadioValue = null;
    }
  }

  // Function to show processing indicator on a card (called after delay).
  function showProcessingVisual(card) {
    const indicator = document.createElement('span');
    indicator.className = 'member-type-card__processing';
    indicator.textContent = Drupal.t('Processing...');
    const nameEl = card.querySelector('.member-type-card__name');
    if (nameEl) {
      nameEl.appendChild(indicator);
    }
    // Add visual processing state to cards.
    const group = card.closest('.member-type-cards__options');
    if (group) {
      group.querySelectorAll('.member-type-card').forEach((c) => {
        c.classList.add('member-type-card--processing');
      });
    }
  }

  // Function to cancel pending visual feedback.
  function cancelProcessingVisual() {
    if (processingVisualTimeout) {
      clearTimeout(processingVisualTimeout);
      processingVisualTimeout = null;
    }
  }

  // Function to schedule delayed visual feedback.
  function scheduleProcessingVisual(card) {
    // Cancel any existing timeout.
    cancelProcessingVisual();
    // Schedule visual feedback after 200ms.
    processingVisualTimeout = setTimeout(() => {
      showProcessingVisual(card);
      processingVisualTimeout = null;
    }, 200);
  }

  // Function to hide processing indicator and cancel any pending visual feedback.
  function hideProcessing() {
    cancelProcessingVisual();
    document.querySelectorAll('.member-type-card__processing').forEach((el) => {
      el.remove();
    });
    document.querySelectorAll('.member-type-card--processing').forEach((el) => {
      el.classList.remove('member-type-card--processing');
    });
  }

  // Function to disable all radio buttons in a member type cards group.
  function disableCardRadios(card) {
    const group = card.closest('.member-type-cards__options');
    if (group) {
      group.querySelectorAll('.member-type-card input[type="radio"]').forEach((radio) => {
        radio.disabled = true;
      });
    }
  }

  // Function to re-enable all radio buttons after AJAX completes.
  function enableAllCardRadios() {
    document.querySelectorAll('.member-type-cards__options').forEach((group) => {
      group.querySelectorAll('.member-type-card input[type="radio"]').forEach((radio) => {
        // Only re-enable if not permanently disabled (e.g., not available for member 1).
        const card = radio.closest('.member-type-card');
        if (card && !card.classList.contains('member-type-card--disabled')) {
          radio.disabled = false;
        }
      });
    });
  }

  // Restore focus and clear processing state once AJAX completes. Drupal's
  // AJAX subsystem still runs on jQuery, so completion only surfaces as
  // jQuery's global 'ajaxComplete' event, not a native DOM event.
  jQuery(document).on('ajaxComplete', () => {
    hideProcessing();
    enableAllCardRadios();
    requestAnimationFrame(() => {
      restoreFocus();
    });
  });

  function showBadgeNameOther(element) {
    if (!element.checked) {
      return;
    }

    const badgeOther = element
      .closest('.member-wrapper')
      ?.querySelector('.edit-members-badge-name-container');

    if (!badgeOther) {
      return;
    }

    const input = badgeOther.querySelector('.edit-members-badge-name-other');
    const label = badgeOther.querySelector('label');

    const show = element.value === 'O';

    badgeOther.hidden = !show;
    input.required = show;
    label.classList.toggle('form-required', show);
  }

  Drupal.behaviors.conreg = {
    attach(context) {
      // Restore focus if we have stored focus info (handles AJAX refresh).
      if (focusedRadioName && context !== document) {
        requestAnimationFrame(() => {
          restoreFocus();
        });
      }

      // Member type card click handler.
      // Clicking anywhere on the card selects the radio and triggers AJAX.
      once('member-type-card', '.member-type-card', context).forEach((card) => {
        // Make links in cards open in new tabs.
        card.querySelectorAll('.member-type-card__description a').forEach((link) => {
          link.setAttribute('target', '_blank');
          link.setAttribute('rel', 'noopener noreferrer');
        });

        card.addEventListener('click', (event) => {
          // Don't handle clicks on links - let them navigate normally.
          if (event.target.closest('a')) {
            return;
          }

          const input = card.querySelector('input[type="radio"]');
          // Don't handle if clicking directly on the (hidden) input.
          if (event.target === input) {
            return;
          }

          selectCard(card);
        });

        // Keyboard support: Enter/Space selects the focused card. Arrow keys
        // move focus to (and select) the previous/next enabled card, matching
        // the native radiogroup interaction pattern.
        card.addEventListener('keydown', (event) => {
          const group = card.closest('.member-type-cards__options');
          if (!group) {
            return;
          }

          switch (event.key) {
            case 'Enter':
            case ' ':
            case 'Spacebar':
              event.preventDefault();
              selectCard(card);
              break;

            case 'ArrowDown':
            case 'ArrowRight': {
              event.preventDefault();
              const enabledCards = getEnabledCards(group);
              const index = enabledCards.indexOf(card);
              if (index === -1) {
                break;
              }
              const next = enabledCards[(index + 1) % enabledCards.length];
              next.focus();
              selectCard(next);
              break;
            }

            case 'ArrowUp':
            case 'ArrowLeft': {
              event.preventDefault();
              const enabledCards = getEnabledCards(group);
              const index = enabledCards.indexOf(card);
              if (index === -1) {
                break;
              }
              const prev = enabledCards[(index - 1 + enabledCards.length) % enabledCards.length];
              prev.focus();
              selectCard(prev);
              break;
            }

            default:
              break;
          }
        });
      });

      // Handle direct input changes (e.g., keyboard navigation).
      once('member-type-card-radio', '.member-type-card input[type="radio"]', context).forEach((input) => {
        input.addEventListener('change', () => {
          const card = input.closest('.member-type-card');
          if (!card) {
            return;
          }
          updateCardSelection(card);

          // Store focus info for restoration after AJAX.
          focusedRadioName = input.name;
          focusedRadioValue = input.value;

          // Defer disabling until after event handlers complete to not interfere with AJAX.
          setTimeout(() => {
            disableCardRadios(card);
            scheduleProcessingVisual(card);
          }, 0);
        });

        // Sync visual state with actual radio state (e.g., after browser back/forward).
        if (input.checked) {
          const card = input.closest('.member-type-card');
          if (card) {
            updateCardSelection(card);
          }
        }
      });

      // Update badge name options.
      once(
        'conreg-name',
        '.edit-members-first-name, .edit-members-last-name',
        context,
      ).forEach((field) => {
        field.addEventListener('input', (event) => {
          const maxLength = drupalSettings.conreg.badge_name_max;

          const wrapper = event.target.closest('.member-wrapper');
          const firstName = wrapper
            .querySelector('.edit-members-first-name')
            .value.trim();
          const lastName = wrapper
            .querySelector('.edit-members-last-name')
            .value.trim();

          const labels = {
            N: `${firstName} ${lastName}`,
            F: firstName,
            L: `${lastName}, ${firstName}`,
          };

          Object.entries(labels).forEach(([option, value]) => {
            wrapper.querySelector(
              `.edit-members-badge-name-option[value="${option}"] + label`,
            ).textContent = value.substring(0, maxLength);
          });
        });
      });

      // Set initial badge options.
      once('conreg-badge', '.edit-members-badge-name-option', context).forEach(
        (option) => {
          showBadgeNameOther(option);

          option.addEventListener('change', () => {
            showBadgeNameOther(option);
          });
        },
      );

      // Free amount changes.
      once('conreg-free', '.edit-free-amt', context).forEach((field) => {
        field.addEventListener('input', (event) => {
          const member = event.target.dataset.member;

          if (member && member !== 'global') {
            const base = Number(
              document.getElementById(`edit-${member}-price-minus-free-amt`)
                .value,
            );

            document.getElementById(`${member}-value`).textContent = (
              base + Number(event.target.value)
            ).toFixed(2);
          }

          let total = Number(
            document.getElementById('edit-total-minus-free-amt').value,
          );

          document.querySelectorAll('.edit-free-amt').forEach((input) => {
            total += Number(input.value);
          });

          document.getElementById('total-value').textContent = total.toFixed(2);

          document.getElementById('edit-payment-submit').value =
            total === 0
              ? drupalSettings.submit.free
              : drupalSettings.submit.payment;
        });
      });
    },
  };
})(Drupal, once);
