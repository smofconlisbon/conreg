/**
 * @file
 * ConReg behavior - handle badge name and pricing updates.
 */

(function (Drupal, once) {
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
