<?php

namespace Drupal\conreg\Controller;

use Drupal\conreg\Service\MemberRepository;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for checking email as you type.
 */
class EmailCheckController extends ControllerBase {

  public function __construct(
    protected FloodInterface $flood,
    protected MemberRepository $memberRepository,
  ) {}

  /**
   * Function called from front end to validate member email uniqueness.
   */
  public function check(Request $request): JsonResponse {

    $identifier = $this->currentUser()->isAuthenticated()
      ? 'uid:' . $this->currentUser()->id()
      : 'ip:' . $request->getClientIp();

    if (!$this->flood->isAllowed(
      'member_email_lookup',
      120,
      60,
      $identifier
    )) {
      return new JsonResponse([
        'status' => 'rate_limited',
      ], 429);
    }

    $this->flood->register(
      name: 'member_email_lookup',
      window: 60,
      identifier: $identifier
    );

    $eid = intval(trim($request->query->get('eid')));
    $email = strtolower(trim($request->query->get('email')));

    $result = $this->memberRepository->emailExists($eid, $email);

    return new JsonResponse(['exists' => $result]);
  }

}
