/**
 * Drupal behavior to check email uniqueness as user types.
 * @param Drupal
 * @param once
 * @param drupalSettings
 */
(function memberEmailCheck(Drupal, once, drupalSettings) {
  // Start remote lookup from settings, but allow to be updated later.
  let remoteLookupEnabled = false;

  // Remove previous warning message.
  function clearWarning(element) {
    const warning = element.parentNode.querySelector('.members-email-warning');

    if (warning) {
      warning.remove();
    }
  }

  // Sets state class of email status indicator.
  function setEmailState(element, state) {
    element.classList.remove(
      'member-email-status--valid',
      'member-email-status--invalid',
      'member-email-status--possible',
      'member-email-status--conflict',
    );

    if (state) {
      element.classList.add(state);
    }

    clearWarning(element);
  }

  function setStateIfValid(status, element) {
    clearWarning(element);
    const value = element.value;

    if (value === '') {
      setEmailState(status);
      return;
    }

    if (!value.includes('@') || !value.includes('.')) {
      setEmailState(status, 'member-email-status--invalid');
      return;
    }

    if (!element.validity.valid) {
      setEmailState(status, 'member-email-status--invalid');
      return;
    }

    setEmailState(status, 'member-email-status--valid');
  }

  // Show warning message below email field.
  function showWarning(element, message) {
    let warning = element.parentNode.querySelector('.members-email-warning');

    if (!warning) {
      warning = document.createElement('div');
      warning.className = 'members-email-warning';
      element.parentNode.appendChild(warning);
    }

    warning.innerHTML = message;
  }

  // Compare with other email fields on current form.
  function findMatchingEmail(emailFields, element, matcher) {
    const match = emailFields.find((field) => {
      if (field === element) {
        return false;
      }

      const email = field.value.trim().toLowerCase();

      return email && matcher(email);
    });

    return match ? match.value.trim().toLowerCase() : null;
  }

  // Locally validate against other emails on form and members for user.
  function validateLocal(element, emailFields) {
    const value = element.value.trim().toLowerCase();
    const status = element.nextElementSibling;

    if (value.length < 3) {
      setStateIfValid(status, element);
      return;
    }

    // Check other members on the current form.
    const exact = findMatchingEmail(
      emailFields,
      element,
      (email) => email === value,
    );

    if (exact) {
      setEmailState(status, 'member-email-status--conflict');

      showWarning(
        element,
        Drupal.t(
          'You may not use the same email for multiple members: @email. Email is only required for the first member, but must be unique if entered.',
          {
            '@email': exact,
            '@url': drupalSettings.conreg.memberPortalUrl,
          },
        ),
      );
      return false;
    }

    const partial = findMatchingEmail(emailFields, element, (email) =>
      email.startsWith(value),
    );

    if (partial) {
      setEmailState(status, 'member-email-status--possible');

      showWarning(
        element,
        Drupal.t(
          'Note: Already entered for another member: @email. Email is only required for the first member, but must be unique if entered.',
          {
            '@email': partial,
            '@url': drupalSettings.conreg.memberPortalUrl,
          },
        ),
      );
      return false;
    }

    // Check current user's previously registered emails.
    const userEmails = drupalSettings.conreg.userEmails || [];
    const match = userEmails.find((email) =>
      email.toLowerCase().startsWith(value),
    );

    if (match) {
      const email = match.toLowerCase().trim();
      setEmailState(
        status,
        email === value
          ? 'member-email-status--conflict'
          : 'member-email-status--possible',
      );

      showWarning(
        element,
        Drupal.t(
          'Note: You have previously registered: @email. Please visit our <a href="@url">member portal</a> to confirm your membership status.',
          {
            '@email': email,
            '@url': drupalSettings.conreg.memberPortalUrl,
          },
        ),
      );
      return false;
    }

    setStateIfValid(status, element);
    return true;
  }

  // Helper function to lookup email.
  async function lookupEmail(email) {
    const eid = drupalSettings.conreg.eid;
    if (!remoteLookupEnabled) {
      return null;
    }

    try {
      const response = await fetch(
        Drupal.url(
          `members/email-check?eid=${encodeURIComponent(eid)}&email=${encodeURIComponent(email)}`,
        ),
        {
          headers: {
            Accept: 'application/json',
          },
        },
      );

      if (response.status === 403) {
        remoteLookupEnabled = false;
        return null;
      }

      if (!response.ok) {
        return null;
      }

      return await response.json();
    } catch (e) {
      console.error('Email lookup failed', e);
      return null;
    }
  }

  // Validate the form against all registered members.
  async function validateRemote(element) {
    const status = element.nextElementSibling;

    const value = element.value.toLowerCase();

    const atPos = value.indexOf('@');

    if (atPos === -1) {
      clearWarning(element);
      return;
    }

    const localPart = value.substring(0, atPos);
    const domainPart = value.substring(atPos + 1);

    if (!localPart || !domainPart) {
      clearWarning(element);
      return;
    }

    const commonDomains = drupalSettings.conreg.commonDomains || [];

    // Check for partial common-domain matches.
    const matchingDomain = commonDomains.find((domain) =>
      domain.startsWith(domainPart),
    );
    if (matchingDomain && matchingDomain !== domainPart) {
      // Assemble the email for the found domain.
      const likelyEmail = `${localPart}@${matchingDomain}`;
      // Look up matching members with common domain.
      const result = await lookupEmail(likelyEmail);
      // Display warning about possible duplicate.
      if (result?.exists) {
        setEmailState(status, 'member-email-status--possible');

        showWarning(
          element,
          Drupal.t(
            'A membership has already been registered with a similar address for a popular domain. Please visit our <a href="@url">member portal</a> to confirm your membership status.',
            { '@url': drupalSettings.conreg.memberPortalUrl },
          ),
        );
      }
      return;
    }

    // Look up the email address entered.
    const result = await lookupEmail(value);

    // Display warning.
    if (result.exists) {
      setEmailState(status, 'member-email-status--conflict');

      showWarning(
        element,
        Drupal.t(
          'A membership has already been registered with this email address. Please visit our <a href="@url">member portal</a> to confirm your membership status.',
          { '@url': drupalSettings.conreg.memberPortalUrl },
        ),
      );
      return;
    }

    setStateIfValid(status, element);
  }

  // Drupal behavior to add elements and handlers to form.
  Drupal.behaviors.memberEmailValidation = {
    attach(context) {
      remoteLookupEnabled = drupalSettings.conreg.emailLookupEnabled;

      const emailFields = Array.from(
        document.querySelectorAll('.edit-members-email'),
      );

      once('member-email-validation', '.edit-members-email', context).forEach(
        (element) => {
          const status = document.createElement('span');
          status.className = 'member-email-status';

          element.insertAdjacentElement('afterend', status);

          element.addEventListener(
            'input',
            Drupal.debounce(() => {
              // Carry out local validation within the form.
              const localValid = validateLocal(element, emailFields);

              // If email lookup is not enabled, end validation.
              if (!remoteLookupEnabled) {
                return;
              }

              // Carry out remote validation.
              if (localValid && element.value.includes('@')) {
                validateRemote(element);
              }
            }, 300),
          );
        },
      );
    },
  };
})(Drupal, once, drupalSettings);
