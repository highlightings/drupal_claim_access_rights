<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Interface for managing entity claim access rights and grants.
 */
interface ClaimAccessManagerInterface {

  public const MODE_EXCLUSIVE = 'exclusive';
  public const MODE_REPLACE = 'replace';
  public const MODE_APPEND = 'append';

  public const STATUS_ACTIVE = 'active';
  public const STATUS_REVOKED = 'revoked';
  public const STATUS_EXPIRED = 'expired';
  public const STATUS_REPLACED = 'replaced';
  public const STATUS_PENDING = 'pending';

  public const RIGHT_VIEW = 'view';
  public const RIGHT_EDIT = 'edit';

  /**
   * Grants access rights on an entity to a user.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   * @param int $uid
   *   The claimant user ID.
   * @param array $rights
   *   Array of rights to grant (e.g. ['view', 'edit']).
   * @param string|null $mode
   *   Access mode: exclusive, replace, or append. Defaults to configured mode.
   * @param int|null $expires_at
   *   Timestamp when access expires, or NULL to compute from settings.
   * @param string|null $notes
   *   Optional notes.
   * @param int|null $submission_id
   *   Optional webform submission ID.
   *
   * @return int
   *   The created or updated grant ID.
   */
  public function grantAccess(
    string $entity_type,
    int $entity_id,
    int $uid,
    array $rights,
    ?string $mode = null,
    ?int $expires_at = null,
    ?string $notes = null,
    ?int $submission_id = null
  ): int;

  /**
   * Checks if an account has access to perform an operation on an entity via claim grant.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param string $op
   *   The operation: 'view', 'update', or 'delete'.
   *
   * @return bool
   *   TRUE if active unexpired grant permits the operation, FALSE otherwise.
   */
  public function hasAccess(EntityInterface $entity, AccountInterface $account, string $op): bool;

  /**
   * Checks whether an entity is currently claimable by an account.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   Optional account to check against.
   *
   * @return array
   *   Array with keys:
   *   - 'claimable': bool
   *   - 'reason': string
   *   - 'mode': string
   *   - 'active_grants': array
   */
  public function isClaimable(EntityInterface $entity, ?AccountInterface $account = null): array;

  /**
   * Returns active grants for an entity.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   *
   * @return array
   *   Array of grant records.
   */
  public function getActiveGrants(string $entity_type, int $entity_id): array;

  /**
   * Returns grants for a specific user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   Array of grant records.
   */
  public function getUserGrants(int $uid): array;

  /**
   * Returns a single grant by ID.
   */
  public function getGrant(int $grant_id): ?array;

  /**
   * Revokes a specific grant.
   *
   * @param int $grant_id
   *   The grant ID.
   *
   * @return bool
   *   TRUE if revoked, FALSE otherwise.
   */
  public function revokeGrant(int $grant_id): bool;

  /**
   * Purges / marks expired grants.
   *
   * @return int
   *   Number of expired grants updated.
   */
  public function purgeExpiredGrants(): int;

}
