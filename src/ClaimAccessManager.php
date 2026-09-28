<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Service managing claim access grants and permissions across all content entities.
 */
final class ClaimAccessManager implements ClaimAccessManagerInterface {

  private readonly LoggerChannelInterface $logger;

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('claim_access_rights');
  }

  /**
   * Checks whether a specific entity type and bundle is enabled for claiming.
   */
  public function isEntityTypeBundleEnabled(string $entity_type, string $bundle): bool {
    $config = $this->configFactory->get('claim_access_rights.settings');
    $enabled_types = (array) $config->get('enabled_entity_types');

    if (!empty($enabled_types)) {
      return !empty($enabled_types[$entity_type]) && in_array($bundle, (array) $enabled_types[$entity_type], TRUE);
    }

    // Fallback backward compatibility for legacy enabled_bundles (node-only).
    if ($entity_type === 'node') {
      $node_bundles = (array) $config->get('enabled_bundles') ?: ['listing'];
      return in_array($bundle, $node_bundles, TRUE);
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
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
  ): int {
    $config = $this->configFactory->get('claim_access_rights.settings');
    $mode = $mode ?: (string) $config->get('claim_mode') ?: self::MODE_EXCLUSIVE;
    $now = $this->time->getRequestTime();
    $starts_at = ($starts_at !== null && $starts_at > 0) ? $starts_at : $now;

    // Calculate expiry if not explicitly passed.
    if ($expires_at === null) {
      $expiry_type = (string) $config->get('expiry_type') ?: 'days';
      $days = (int) $config->get('default_expiry_days') ?: 30;
      if ($expiry_type === 'days' && $days > 0) {
        $expires_at = $starts_at + ($days * 86400);
      }
      else {
        $expires_at = 0;
      }
    }

    $rights_str = implode(',', array_unique(array_filter($rights)));
    if (empty($rights_str)) {
      $rights_str = self::RIGHT_VIEW . ',' . self::RIGHT_EDIT;
    }

    // Handle claim mode rules.
    switch ($mode) {
      case self::MODE_EXCLUSIVE:
        $conflict = $this->getOverlappingExclusiveGrant($entity_type, $entity_id, $starts_at, $expires_at);
        if ($conflict && (int) $conflict['uid'] !== $uid) {
          $conf_start = date('Y-m-d', (int) ($conflict['starts_at'] ?: $conflict['created']));
          $conf_end = (int) $conflict['expires_at'] === 0 ? 'Indefinite' : date('Y-m-d', (int) $conflict['expires_at']);
          throw new \RuntimeException(sprintf(
            'Exclusive access is already reserved on %s ID %d for overlapping duration (%s to %s).',
            $entity_type,
            $entity_id,
            $conf_start,
            $conf_end
          ));
        }

        // If the same user has an active grant on this entity, update it.
        $active_user_grant = $this->database->select('claim_access_grants', 'c')
          ->fields('c', ['id'])
          ->condition('entity_type', $entity_type)
          ->condition('entity_id', $entity_id)
          ->condition('uid', $uid)
          ->condition('status', self::STATUS_ACTIVE)
          ->execute()
          ->fetchField();

        if ($active_user_grant) {
          $existing_id = (int) $active_user_grant;
          $this->database->update('claim_access_grants')
            ->fields([
              'rights' => $rights_str,
              'starts_at' => $starts_at,
              'expires_at' => $expires_at,
              'mode' => $mode,
              'notes' => $notes,
              'submission_id' => $submission_id ?? 0,
            ])
            ->condition('id', $existing_id)
            ->execute();
          $grant_id = $existing_id;
          break;
        }

        $grant_id = (int) $this->database->insert('claim_access_grants')
          ->fields([
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'uid' => $uid,
            'rights' => $rights_str,
            'mode' => $mode,
            'status' => self::STATUS_ACTIVE,
            'created' => $now,
            'starts_at' => $starts_at,
            'expires_at' => $expires_at,
            'submission_id' => $submission_id ?? 0,
            'notes' => $notes,
          ])
          ->execute();
        break;

      case self::MODE_REPLACE:
        // Revoke / replace previous active grants on this entity.
        $this->database->update('claim_access_grants')
          ->fields(['status' => self::STATUS_REPLACED])
          ->condition('entity_type', $entity_type)
          ->condition('entity_id', $entity_id)
          ->condition('status', self::STATUS_ACTIVE)
          ->execute();

        $grant_id = (int) $this->database->insert('claim_access_grants')
          ->fields([
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'uid' => $uid,
            'rights' => $rights_str,
            'mode' => $mode,
            'status' => self::STATUS_ACTIVE,
            'created' => $now,
            'starts_at' => $starts_at,
            'expires_at' => $expires_at,
            'submission_id' => $submission_id ?? 0,
            'notes' => $notes,
          ])
          ->execute();
        break;

      case self::MODE_APPEND:
      default:
        // Check if this specific user already has an active grant.
        $existing = $this->database->select('claim_access_grants', 'c')
          ->fields('c', ['id'])
          ->condition('entity_type', $entity_type)
          ->condition('entity_id', $entity_id)
          ->condition('uid', $uid)
          ->condition('status', self::STATUS_ACTIVE)
          ->execute()
          ->fetchField();

        if ($existing) {
          $this->database->update('claim_access_grants')
            ->fields([
              'rights' => $rights_str,
              'starts_at' => $starts_at,
              'expires_at' => $expires_at,
              'mode' => $mode,
              'notes' => $notes,
              'submission_id' => $submission_id ?? 0,
            ])
            ->condition('id', (int) $existing)
            ->execute();
          $grant_id = (int) $existing;
        }
        else {
          $grant_id = (int) $this->database->insert('claim_access_grants')
            ->fields([
              'entity_type' => $entity_type,
              'entity_id' => $entity_id,
              'uid' => $uid,
              'rights' => $rights_str,
              'mode' => $mode,
              'status' => self::STATUS_ACTIVE,
              'created' => $now,
              'starts_at' => $starts_at,
              'expires_at' => $expires_at,
              'submission_id' => $submission_id ?? 0,
              'notes' => $notes,
            ])
            ->execute();
        }
        break;
    }

    $this->logger->notice('Granted claim access ID @id to UID @uid for @type @eid (Mode: @mode, Rights: @rights).', [
      '@id' => $grant_id,
      '@uid' => $uid,
      '@type' => $entity_type,
      '@eid' => $entity_id,
      '@mode' => $mode,
      '@rights' => $rights_str,
    ]);

    // Invalidate entity cache and claims cache tags.
    $this->cacheTagsInvalidator->invalidateTags([
      $entity_type . ':' . $entity_id,
      'claim_access_grants',
    ]);

    return $grant_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getOverlappingExclusiveGrant(
    string $entity_type,
    int $entity_id,
    int $starts_at,
    int $expires_at,
    ?int $exclude_grant_id = null
  ): ?array {
    $query = $this->database->select('claim_access_grants', 'c')
      ->fields('c')
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', $entity_id)
      ->condition('status', self::STATUS_ACTIVE)
      ->condition('mode', self::MODE_EXCLUSIVE);

    if ($exclude_grant_id) {
      $query->condition('id', $exclude_grant_id, '<>');
    }

    $grants = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $now = $this->time->getRequestTime();

    foreach ($grants as $grant) {
      $g_start = (int) ($grant['starts_at'] ?: $grant['created']);
      $g_end = (int) $grant['expires_at'];

      // Skip expired grants.
      if ($g_end > 0 && $g_end <= $now) {
        continue;
      }

      $no_overlap = FALSE;
      // If requested interval ends before existing grant starts:
      if ($expires_at > 0 && $expires_at <= $g_start) {
        $no_overlap = TRUE;
      }
      // If existing grant ends before requested interval starts:
      if ($g_end > 0 && $starts_at >= $g_end) {
        $no_overlap = TRUE;
      }

      if (!$no_overlap) {
        return $grant;
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function hasAccess(EntityInterface $entity, AccountInterface $account, string $op): bool {
    if ($account->isAnonymous() || !$entity->id()) {
      return FALSE;
    }

    // Check if the entity type and bundle is enabled for claiming.
    if (!$this->isEntityTypeBundleEnabled($entity->getEntityTypeId(), $entity->bundle())) {
      return FALSE;
    }

    $record = $this->database->select('claim_access_grants', 'c')
      ->fields('c')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', (int) $entity->id())
      ->condition('uid', (int) $account->id())
      ->condition('status', self::STATUS_ACTIVE)
      ->execute()
      ->fetchAssoc();

    if (!$record) {
      return FALSE;
    }

    $starts_at = (int) ($record['starts_at'] ?: $record['created']);
    $expires_at = (int) $record['expires_at'];
    $now = $this->time->getRequestTime();

    // Check if access period has started yet.
    if ($starts_at > $now) {
      return FALSE;
    }

    // Check expiration.
    if ($expires_at > 0 && $expires_at <= $now) {
      // Mark as expired.
      $this->database->update('claim_access_grants')
        ->fields(['status' => self::STATUS_EXPIRED])
        ->condition('id', (int) $record['id'])
        ->execute();

      $this->cacheTagsInvalidator->invalidateTags([
        $entity->getEntityTypeId() . ':' . $entity->id(),
        'claim_access_grants',
      ]);
      return FALSE;
    }

    // Map operation to rights: 'update' maps to 'edit'.
    $required_right = match ($op) {
      'update' => self::RIGHT_EDIT,
      'view' => self::RIGHT_VIEW,
      default => $op,
    };

    $rights = explode(',', (string) $record['rights']);
    return in_array($required_right, $rights, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function isClaimable(EntityInterface $entity, ?AccountInterface $account = null): array {
    $entity_type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();

    if (!$this->isEntityTypeBundleEnabled($entity_type, $bundle)) {
      return [
        'claimable' => FALSE,
        'reason' => sprintf('Claiming is not enabled for %s (%s).', $entity_type, $bundle),
        'mode' => self::MODE_EXCLUSIVE,
        'active_grants' => [],
      ];
    }

    $config = $this->configFactory->get('claim_access_rights.settings');
    $mode = (string) $config->get('claim_mode') ?: self::MODE_EXCLUSIVE;
    $active_grants = $this->getActiveGrants($entity_type, (int) $entity->id());

    if ($account && !$account->isAnonymous()) {
      foreach ($active_grants as $grant) {
        if ((int) $grant['uid'] === (int) $account->id()) {
          return [
            'claimable' => FALSE,
            'reason' => 'You already hold an active access grant for this item.',
            'mode' => $mode,
            'active_grants' => $active_grants,
            'user_is_claimant' => TRUE,
            'grant' => $grant,
          ];
        }
      }
    }

    // If Exclusive mode and another user has an active grant:
    if ($mode === self::MODE_EXCLUSIVE && !empty($active_grants)) {
      foreach ($active_grants as $grant) {
        if ((int) $grant['expires_at'] === 0) {
          // Permanently claimed under exclusive mode: new requests impossible, button grayed out.
          return [
            'claimable' => FALSE,
            'permanently_claimed' => TRUE,
            'reason' => 'This item has been permanently claimed with exclusive access. New requests cannot be accepted.',
            'mode' => $mode,
            'active_grants' => $active_grants,
          ];
        }
      }

      // If active exclusive grants exist with finite expiry:
      return [
        'claimable' => TRUE,
        'has_exclusive_windows' => TRUE,
        'reason' => 'Exclusive access is reserved for specific time windows. You can request access for non-overlapping dates.',
        'mode' => $mode,
        'active_grants' => $active_grants,
      ];
    }

    return [
      'claimable' => TRUE,
      'reason' => 'This item is available to claim.',
      'mode' => $mode,
      'active_grants' => $active_grants,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveGrants(string $entity_type, int $entity_id): array {
    $records = $this->database->select('claim_access_grants', 'c')
      ->fields('c')
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', $entity_id)
      ->condition('status', self::STATUS_ACTIVE)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $now = $this->time->getRequestTime();
    $active = [];

    foreach ($records as $record) {
      $expires_at = (int) $record['expires_at'];
      if ($expires_at > 0 && $expires_at <= $now) {
        // Expired grant found: update status.
        $this->database->update('claim_access_grants')
          ->fields(['status' => self::STATUS_EXPIRED])
          ->condition('id', (int) $record['id'])
          ->execute();
      }
      else {
        $active[] = $record;
      }
    }

    return $active;
  }

  /**
   * {@inheritdoc}
   */
  public function getUserGrants(int $uid): array {
    return $this->database->select('claim_access_grants', 'c')
      ->fields('c')
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * {@inheritdoc}
   */
  public function getGrant(int $grant_id): ?array {
    $record = $this->database->select('claim_access_grants', 'c')
      ->fields('c')
      ->condition('id', $grant_id)
      ->execute()
      ->fetchAssoc();

    return $record ?: null;
  }

  /**
   * {@inheritdoc}
   */
  public function revokeGrant(int $grant_id): bool {
    $grant = $this->getGrant($grant_id);
    if (!$grant) {
      return FALSE;
    }

    $this->database->update('claim_access_grants')
      ->fields(['status' => self::STATUS_REVOKED])
      ->condition('id', $grant_id)
      ->execute();

    $this->cacheTagsInvalidator->invalidateTags([
      $grant['entity_type'] . ':' . $grant['entity_id'],
      'claim_access_grants',
    ]);

    $this->logger->notice('Revoked claim access grant @id for user @uid on @type @eid.', [
      '@id' => $grant_id,
      '@uid' => $grant['uid'],
      '@type' => $grant['entity_type'],
      '@eid' => $grant['entity_id'],
    ]);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function extendGrant(int $grant_id, int $additional_days = 30): bool {
    $grant = $this->getGrant($grant_id);
    if (!$grant) {
      return FALSE;
    }

    $now = $this->time->getRequestTime();
    $current_expiry = (int) $grant['expires_at'];
    $base_time = ($current_expiry > $now) ? $current_expiry : $now;
    $new_expiry = $base_time + ($additional_days * 86400);

    $this->database->update('claim_access_grants')
      ->fields([
        'expires_at' => $new_expiry,
        'status' => self::STATUS_ACTIVE,
      ])
      ->condition('id', $grant_id)
      ->execute();

    $this->cacheTagsInvalidator->invalidateTags([
      $grant['entity_type'] . ':' . $grant['entity_id'],
      'claim_access_grants',
    ]);

    $this->logger->notice('Extended grant @id by @days days to @expiry.', [
      '@id' => $grant_id,
      '@days' => $additional_days,
      '@expiry' => date('Y-m-d H:i:s', $new_expiry),
    ]);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function requestExtension(int $grant_id, int $additional_days = 30, ?string $reason = null): bool {
    $grant = $this->getGrant($grant_id);
    if (!$grant) {
      return FALSE;
    }

    $config = $this->configFactory->get('claim_access_rights.settings');
    $auto_approve = (bool) ($config->get('user_extension_auto_approve') ?? TRUE);

    $now_date = date('Y-m-d H:i');
    $existing_notes = (string) ($grant['notes'] ?? '');

    if ($auto_approve) {
      $this->extendGrant($grant_id, $additional_days);
      $note_line = "[{$now_date}] Auto-approved extension: +{$additional_days} days." . ($reason ? " Reason: {$reason}" : '');
      $updated_notes = trim($existing_notes . "\n" . $note_line);
      $this->database->update('claim_access_grants')
        ->fields(['notes' => $updated_notes])
        ->condition('id', $grant_id)
        ->execute();

      $this->logger->notice('User extension auto-approved for grant @id (+@days days).', [
        '@id' => $grant_id,
        '@days' => $additional_days,
      ]);
      return TRUE;
    }

    // Manual approval mode: mark as pending extension review.
    $note_line = "[{$now_date}] Extension requested: +{$additional_days} days." . ($reason ? " Reason: {$reason}" : '');
    $updated_notes = trim($existing_notes . "\n" . $note_line);
    $this->database->update('claim_access_grants')
      ->fields([
        'status' => self::STATUS_PENDING,
        'notes' => $updated_notes,
      ])
      ->condition('id', $grant_id)
      ->execute();

    $this->cacheTagsInvalidator->invalidateTags([
      $grant['entity_type'] . ':' . $grant['entity_id'],
      'claim_access_grants',
    ]);

    $this->logger->notice('User extension requested for grant @id (+@days days, pending review).', [
      '@id' => $grant_id,
      '@days' => $additional_days,
    ]);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteGrant(int $grant_id): bool {
    $grant = $this->getGrant($grant_id);
    if (!$grant) {
      return FALSE;
    }

    $this->database->delete('claim_access_grants')
      ->condition('id', $grant_id)
      ->execute();

    $this->cacheTagsInvalidator->invalidateTags([
      $grant['entity_type'] . ':' . $grant['entity_id'],
      'claim_access_grants',
    ]);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatistics(): array {
    $now = $this->time->getRequestTime();
    $seven_days_later = $now + (7 * 86400);

    $grants = $this->database->select('claim_access_grants', 'c')
      ->fields('c')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $stats = [
      'total' => count($grants),
      'active' => 0,
      'no_expiry' => 0,
      'expiring_soon' => 0,
      'expired' => 0,
      'replaced' => 0,
      'revoked' => 0,
      'by_entity_type' => [],
    ];

    foreach ($grants as $g) {
      $type = $g['entity_type'];
      $stats['by_entity_type'][$type] = ($stats['by_entity_type'][$type] ?? 0) + 1;

      $status = $g['status'];
      $expires_at = (int) $g['expires_at'];

      if ($status === self::STATUS_ACTIVE) {
        if ($expires_at > 0 && $expires_at <= $now) {
          $stats['expired']++;
        }
        else {
          $stats['active']++;
          if ($expires_at === 0) {
            $stats['no_expiry']++;
          }
          elseif ($expires_at > $now && $expires_at <= $seven_days_later) {
            $stats['expiring_soon']++;
          }
        }
      }
      elseif ($status === self::STATUS_EXPIRED) {
        $stats['expired']++;
      }
      elseif ($status === self::STATUS_REPLACED) {
        $stats['replaced']++;
      }
      elseif ($status === self::STATUS_REVOKED) {
        $stats['revoked']++;
      }
    }

    return $stats;
  }

  /**
   * {@inheritdoc}
   */
  public function purgeExpiredGrants(): int {
    $now = $this->time->getRequestTime();
    $updated = $this->database->update('claim_access_grants')
      ->fields(['status' => self::STATUS_EXPIRED])
      ->condition('status', self::STATUS_ACTIVE)
      ->condition('expires_at', 0, '>')
      ->condition('expires_at', $now, '<=')
      ->execute();

    if ($updated > 0) {
      $this->cacheTagsInvalidator->invalidateTags(['claim_access_grants']);
      $this->logger->info('Purged @count expired claim access grants.', ['@count' => $updated]);
    }

    return (int) $updated;
  }

}
