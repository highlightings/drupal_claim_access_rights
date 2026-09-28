<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Attaches custom access checks to claim_access_rights routes.
 */
final class ClaimAccessRightsRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('view.claim_access_grants.page_user_claims')) {
      $route->setRequirement('_claim_access_user_check', 'TRUE');
    }
  }

}
