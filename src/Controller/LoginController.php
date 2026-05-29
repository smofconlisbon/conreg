<?php

namespace Drupal\conreg\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\conreg\ConregConfig;
use Drupal\conreg\Service\MemberStorage;
use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Returns responses for ConReg routes.
 */
class LoginController extends ControllerBase {

  /**
   * The controller constructor.
   *
   * @param \Drupal\conreg\Service\MemberStorage $memberStorage
   *   The member storage service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected MemberStorage $memberStorage,
    protected TimeInterface $time,
  ) {}

  /**
   * Check valid member credentials, login and redirect to member portal.
   */
  public function memberLoginAndRedirect($mid, $key, $expiry): array|RedirectResponse {

    // Check member credentials valid.
    $member = $this->memberStorage->load([
      'mid' => $mid,
      'random_key' => $key,
      'login_exp_date' => $expiry,
      'is_deleted' => 0,
    ]);
    if (empty($member['mid'])) {
      $content['markup'] = [
        '#markup' => $this->t('Invalid credentials.'),
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];
      return $content;
    }

    // Check if login has expired.
    if (empty($member['login_exp_date'] > $this->time->getRequestTime())) {
      $content['markup'] = [
        '#markup' => $this->t('Login has expired. Please use Member Check to generate a new login link.'),
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];
      return $content;
    }

    // Check if user already exists.
    $user = user_load_by_mail($member['email']);
    $currentUser = $this->currentUser();

    // Check if user already logged in. If so, redirect to member portal.
    if ($currentUser && $user && $user->id() == $currentUser->id()) {
      // Redirect to member portal.
      return $this->redirect('conreg_portal', ['eid' => $member['eid']], ['absolute' => TRUE]);
    }

    // If user doesn't exist, create new user.
    if (!$user) {
      $language = $this->languageManager()->getCurrentLanguage()->getId();
      $user = User::create([
        'name' => $member['email'],
        'mail' => $member['email'],
      ]);
      $user->set("langcode", $language);
      $user->set("preferred_langcode", $language);
      $user->set("preferred_admin_langcode", $language);
      // Set the user timezone to the site default timezone.
      $dateConfig = $this->config('system.date');
      $config_data_default_timezone = $dateConfig->get('timezone.default');
      $user->set('timezone', $config_data_default_timezone ?: @date_default_timezone_get());
      // NOTE: login will fail silently if not activated!
      $user->activate();
      $user->save();
    }

    // Check if role needs to be added.
    $config = ConregConfig::getConfig($member['eid']);
    $addRole = $config->get('member_portal.add_role');
    if ($addRole) {
      // Check if user has role already.
      if (!$user->hasRole($addRole)) {
        // They don't, so we need to add it.
        $user->addRole($addRole);
        $user->save();
      }
    }

    // Login user.
    user_login_finalize($user);

    return $this->redirect('conreg_portal', ['eid' => $member['eid']], ['absolute' => TRUE]);
  }

}
