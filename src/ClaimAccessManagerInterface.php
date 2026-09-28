<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Interface for managing entity claim access rights and grants across all content entities.
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
   *   The entity type ID (e.g. node, block_content, media, taxonomy_term).
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
   * @param int|null $starts_at
   *   Optional start timestamp when the grant begins. Defaults to creation time.
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
    ?int $submission_id = null,
    ?int $starts_at = null
  ): int;

  /**
   * Checks if a requested access interval overlaps with an active exclusive grant.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The target entity ID.
   * @param int $starts_at
   *   The requested start timestamp.
   * @param int $expires_at
   *   The requested expiration timestamp (0 for indefinite / permanent).
   * @param int|null $exclude_grant_id
   *   Optional grant ID to exclude (e.g. when renewing).
   *
   * @return array|null
   *   The conflicting grant record if an overlap exists, NULL otherwise.
   */
  public function getOverlappingExclusiveGrant(
    string $entity_type,
    int $entity_id,
    int $starts_at,
    int $expires_at,
    ?int $exclude_grant_id = null
  ): ?array;

  /**
   * Checks if an account has access to perform an operation on an entity via claim grant.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity (node, block_content, media, taxonomy_term, etc.).
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
   * Extends the expiry of an existing grant.
   *
   * @param int $grant_id
   *   The grant ID.
   * @param int $additional_days
   *   Number of days to extend.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise.
   */
  public function extendGrant(int $grant_id, int $additional_days = 30, bool $reinstate = FALSE): bool;

  /**
   * Submits an extension request for an access grant.
   *
   * @param int $grant_id
   *   The grant ID.
   * @param int $additional_days
   *   The requested additional days.
   * @param string|null $reason
   *   The reason or justification for the extension.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise.
   */
  public function requestExtension(int $grant_id, int $additional_days = 30, ?string $reason = null): bool;

  /**
   * Deletes a grant permanently from the registry.
   */
  public function deleteGrant(int $grant_id): bool;

  /**
   * Deletes all access grants associated with a specific entity.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   *
   * @return int
   *   Number of deleted grant records.
   */
  public function deleteGrantsForEntity(string $entity_type, int $entity_id): int;

  /**
   * Deletes all access grants belonging to a specific user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return int
   *   Number of deleted grant records.
   */
  public function deleteGrantsForUser(int $uid): int;

  /**
   * Returns aggregate statistics for sitewide claims dashboard.
   *
   * @return array
   *   Keys: total, active, expiring_soon, expired, replaced, revoked, by_entity_type.
   */
  public function getStatistics(): array;

  /**
   * Purges / marks expired grants.
   *
   * @return int
   *   Number of expired grants updated.
   */
  public function purgeExpiredGrants(): int;

  /**
   * Checks whether an entity type and bundle is enabled for claiming.
   */
  public function isEntityTypeBundleEnabled(string $entity_type, string $bundle): bool;

  /**
   * Validates a requested access window against the configured limits.
   *
   * @return string|null
   *   A translated error message, or NULL when the window is acceptable.
   */
  public function validateClaimWindow(int $starts_at, int $expires_at): ?string;

  /**
   * Checks a user's number of active claims against the configured maximum.
   *
   * @return string|null
   *   A translated error message, or NULL when the user may claim more.
   */
  public function validateUserClaimLimit(int $uid): ?string;

  /**
   * Cache metadata that any access result or output derived from grants needs.
   *
   * @return array{tags: string[], max_age: int}
   *   Per-entity cache tags plus a max-age ending at the account's next grant
   *   start or expiry, so time-based access never outlives its window.
   */
  public function getAccessCacheMetadata(EntityInterface $entity, AccountInterface $account): array;

  /**
   * Seconds until the next start or expiry boundary among the given grants.
   *
   * @return int
   *   Cache::PERMANENT (-1) when no boundary lies in the future.
   */
  public function getGrantsMaxAge(array $grants): int;

  /**
   * Checks whether a grant records an extension request awaiting review.
   *
   * @param array $grant
   *   The grant record array.
   *
   * @return bool
   *   TRUE if an extension request is pending review.
   */
  public static function isExtensionPending(array $grant): bool;

}
