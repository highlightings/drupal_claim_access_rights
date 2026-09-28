<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access check for user claims page.
 */
final class UserClaimsAccessCheck implements AccessInterface {

  public function access(AccountInterface $account, mixed $user = null): AccessResultInterface {
    if (!$account->isAuthenticated()) {
      return AccessResult::forbidden()->cachePerUser();
    }

    if ($account->hasPermission('administer claim access rights')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $target_uid = 0;
    if ($user instanceof AccountInterface) {
      $target_uid = (int) $user->id();
    }
    elseif (is_numeric($user)) {
      $target_uid = (int) $user;
    }

    if ($target_uid > 0 && (int) $account->id() === $target_uid && $account->hasPermission('claim access rights')) {
      return AccessResult::allowed()->cachePerUser();
    }

    return AccessResult::forbidden()->cachePerUser();
  }

}
