<?php

declare(strict_types=1);

namespace Drupal\conreg\Hook;

use Drupal\Component\Render\MarkupInterface;
use Drupal\conreg\Service\EmailTokenContext;
use Drupal\conreg\Service\MemberDetailsFormatter;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\easy_email\Entity\EasyEmailInterface;

/**
 * Hook implementations for conreg.
 */
class ConregTokenHooks {
  use StringTranslationTrait;

  /**
   * Constructor for token hooks.
   */
  public function __construct(
    protected MemberDetailsFormatter $memberDetailsFormatter,
    protected EmailTokenContext $emailTokenContext,
  ) {}

  /**
   * Fields available on a single member, keyed by their token name.
   *
   * `member-type`, `country`, `member-price`, and a few others below are
   * label/currency lookups, not raw columns - they resolve correctly
   * because ConregEmailer resolves each member via
   * MemberPresenter::present() before building $data['members'], not
   * because of any lookup logic in this class. This class only ever
   * reads already-resolved properties, deliberately staying
   * container-free (no config/DB access) so it can be Unit-tested.
   */
  private const MEMBER_FIELDS = [
    'first-name' => 'first_name',
    'last-name' => 'last_name',
    'badge-name' => 'badge_name',
    'email' => 'email',
    'street' => 'street',
    'street2' => 'street2',
    'city' => 'city',
    'county' => 'county',
    'postcode' => 'postcode',
    'country' => 'country',
    'member-type' => 'member_type',
    'member-price' => 'member_price',
    // member-total = member-price + paid add-ons; payment-amount is the
    // same total broadcast to every member in the group (i.e. it's the
    // group's grand total, not a per-member figure) - both computed by
    // MemberPresenter::present(), including its
    // Addons::getMemberAddons() aggregation, same as everything else in
    // this list.
    'member-total' => 'member_total',
    'payment-amount' => 'payment_amount',
    // payment-id is a plain raw column (the payment gateway's reference,
    // e.g. a Stripe payment_intent ID) - unlike the two above, not
    // computed by MemberPresenter::present().
    'payment-id' => 'payment_id',
    'member-no' => 'member_no',
    'display' => 'display',
    'communication-method' => 'communication_method',
    'is-approved' => 'is_approved',
    'is-paid' => 'is_paid',
    'is-deleted' => 'is_deleted',
    // login-url/login-expiry/login-expiry-medium/lead-key/lead-email are
    // only ever set on the primary member ($data['members'][0]), never on
    // the rest of the group - see ConregEmailer, which gets them from
    // MemberPresenter::primaryMemberLoginData()/leaderData() (the
    // underlying random_key/login_exp_date are generated-and-persisted
    // as a side effect, and login-url needs the routing service -
    // neither is something this class should be doing itself).
    'login-url' => 'login_url',
    'login-expiry' => 'login_expiry',
    'login-expiry-medium' => 'login_expiry_medium',
    'lead-key' => 'lead_key',
    'lead-email' => 'lead_email',
  ];

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo() {
    $info = [];
    $info['types']['conreg'] = [
      'name' => $this->t('ConReg'),
      'description' => $this->t('Convention registration tokens.'),
    ];
    $info['types']['conreg-member'] = [
      'name' => $this->t('ConReg member'),
      'description' => $this->t('Fields available on an individual conreg member.'),
    ];
    $info['tokens']['conreg']['event-name'] = [
      'name' => $this->t('Event name'),
      'description' => $this->t('The event the user is registering for.'),
    ];
    $info['tokens']['conreg']['event-email'] = [
      'name' => $this->t('Event email'),
      'description' => $this->t('The email address for the event.'),
    ];
    $info['tokens']['conreg']['member'] = [
      'name' => $this->t('Member'),
      'description' => $this->t('The lead member, e.g. [conreg:member:first-name].'),
      'type' => 'conreg-member',
    ];
    $info['tokens']['conreg']['members'] = [
      'name' => $this->t('Members'),
      'description' => $this->t('A member addressed by position, e.g. [conreg:members:1:first-name] (1 is the lead member), or "all" for a formatted list of every member, e.g. [conreg:members:all:first-name].'),
    ];
    $info['tokens']['conreg']['member-details'] = [
      'name' => $this->t('Member details'),
      'description' => $this->t('A full table of every member in the group - pricing, add-ons, and per-field labels. Renders as an HTML table, or a plain-text block when the "plain_text" token option is set.'),
    ];
    $info['tokens']['conreg-member']['full-name'] = [
      'name' => $this->t('Full name'),
      'description' => $this->t("The member's first and last name combined."),
    ];
    foreach (self::MEMBER_FIELDS as $token_name => $label) {
      $info['tokens']['conreg-member'][$token_name] = [
        'name' => $this->t('@field', ['@field' => str_replace('-', ' ', $token_name)]),
        'description' => $this->t('The member field @field.', ['@field' => $label]),
      ];
    }
    return $info;
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
    $replacements = [];
    if ($type != 'conreg') {
      return $replacements;
    }

    if (!isset($data['event'], $data['members']) && ($data['easy_email'] ?? NULL) instanceof EasyEmailInterface) {
      $data += $this->emailTokenContext->buildFromEmail($data['easy_email']);
    }

    foreach ($tokens as $name => $original) {
      if ($name === 'event-name') {
        $replacements[$original] = $data['event']['name'];
      }
      elseif ($name === 'event-email') {
        $replacements[$original] = $data['event']['email'];
      }
      elseif ($name === 'member-details') {
        $value = $this->memberDetailsValue($data, $options);
        if ($value !== NULL) {
          $replacements[$original] = $value;
        }
      }
      elseif (str_starts_with($name, 'member:')) {
        $member = $data['member'] ?? $data['members'][0] ?? NULL;
        $value = $member ? $this->memberFieldValue(substr($name, strlen('member:')), $member) : NULL;
        if ($value !== NULL) {
          $replacements[$original] = $value;
        }
      }
      elseif (str_starts_with($name, 'members:')) {
        [$position, $field] = explode(':', substr($name, strlen('members:')), 2) + [1 => NULL];
        if ($position === 'all') {
          $values = array_filter(array_map(
            fn ($member) => $this->memberFieldValue($field, $member),
            $data['members'] ?? [],
          ), fn ($value) => !empty($value));
          $replacements[$original] = $this->formatList($values, $bubbleable_metadata);
        }
        elseif (ctype_digit($position)) {
          $member = ($data['members'] ?? [])[((int) $position) - 1] ?? NULL;
          $value = $member ? $this->memberFieldValue($field, $member) : NULL;
          if ($value !== NULL) {
            $replacements[$original] = $value;
          }
        }
      }
    }

    return $replacements;
  }

  /**
   * Resolves the member-details token via MemberDetailsFormatter.
   *
   * Unlike every other case in tokens(), this does real DB/config work
   * (member classes, field options) - it's the one exception to this
   * class staying container-free, deliberately isolated behind the
   * injected formatter service rather than done inline here.
   */
  protected function memberDetailsValue(array $data, array $options): string|MarkupInterface|null {
    $eid = $data['event']['eid'] ?? NULL;
    $members = $data['members'] ?? [];
    if ($eid === NULL || empty($members)) {
      return NULL;
    }
    $sections = $this->memberDetailsFormatter->build(
      (int) $eid,
      array_map(fn ($member) => (array) $member, $members),
    );
    if (empty($options['plain_text'])) {
      // Token::replace() HTML-escapes any non-Markup replacement value, so
      // the table markup needs to be marked safe explicitly - the plain
      // variant is genuinely plain text and must stay an ordinary string.
      return Markup::create($sections['html']);
    }
    return $sections['plain'];
  }

  /**
   * Resolves a single member field token, e.g. "first-name".
   */
  protected function memberFieldValue(?string $field, mixed $member): ?string {
    if ($field === 'full-name') {
      return trim(($member->first_name ?? '') . ' ' . ($member->last_name ?? ''));
    }
    $property = self::MEMBER_FIELDS[$field] ?? NULL;
    if ($property === NULL || !isset($member->$property)) {
      return NULL;
    }
    // Some upstream values (e.g. country names from Drupal core's
    // CountryManager) are TranslatableMarkup, not plain strings - cast
    // explicitly rather than relying on implicit Stringable coercion.
    return (string) $member->$property;
  }

  /**
   * Joins a list of strings into a human-readable, translatable series.
   *
   * E.g. ['James'] => "James"; ['James', 'Jack'] => "James and Jack";
   * ['James', 'Jack', 'Jane'] => "James, Jack, and Jane".
   */
  protected function formatList(array $items, BubbleableMetadata $bubbleable_metadata): string {
    $items = array_values($items);
    switch (count($items)) {
      case 0:
        return '';

      case 1:
        return $items[0];

      case 2:
        $bubbleable_metadata->addCacheContexts(['languages:' . LanguageInterface::TYPE_INTERFACE]);
        return (string) $this->t('@first and @second', [
          '@first' => $items[0],
          '@second' => $items[1],
        ]);

      default:
        $bubbleable_metadata->addCacheContexts(['languages:' . LanguageInterface::TYPE_INTERFACE]);
        $last = array_pop($items);
        return (string) $this->t('@list, and @last', [
          '@list' => implode(', ', $items),
          '@last' => $last,
        ]);
    }
  }

}
