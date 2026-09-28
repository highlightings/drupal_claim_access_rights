<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects the current user to their personal access claims tab.
 */
final class MyClaimsRedirectController extends ControllerBase {

  public function redirectMyClaims(): RedirectResponse {
    $current_user = $this->currentUser();
    if ($current_user->isAnonymous()) {
      return $this->redirect('user.login', [], ['query' => ['destination' => '/my-claims']]);
    }

    return $this->redirect('view.claim_access_grants.page_user_claims', ['user' => $current_user->id()]);
  }

}
