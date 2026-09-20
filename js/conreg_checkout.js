/**
 * @file
 * ConReg payment.
 */

// Adapted to Drupal by James Shields, 5 Oct 2019.

(function ($) {

  // Kept short (rather than 0) just long enough for the "You will be
  // transferred to Stripe" message to register before the page navigates
  // away. A long wait here mainly invited members to double-click, refresh,
  // or navigate back during the delay, which could create a second Stripe
  // Checkout Session for the same payment.
  setTimeout(function()
  {
    var stripe = Stripe(drupalSettings.conreg.checkout.public_key);
    stripe.redirectToCheckout({
      // Make the id field from the Checkout Session creation API response
      // available to this file, so you can provide it as parameter here
      // instead of the {{CHECKOUT_SESSION_ID}} placeholder.
      sessionId: drupalSettings.conreg.checkout.session_id
    }).then(function (result) {
      // If `redirectToCheckout` fails due to a browser or network
      // error, display the localized error message to your customer
      // using `result.error.message`.
    });
  }, 1500);

})(jQuery);
