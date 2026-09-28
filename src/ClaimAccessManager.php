<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Service managing claim access grants and permissions across all content entities.
 */
final class ClaimAccessManager implements ClaimAccessManagerInterface {

  private readonly LoggerChannelInterface $logger;

  /**
   * Per-request cache of user active grants: [uid => ["type:id" => row|null]].
   */
  private array $userGrantIndex = [];

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly LockBackendInterface $lock,
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
    if (!in_array($mode, [self::MODE_EXCLUSIVE, self::MODE_REPLACE, self::MODE_APPEND], TRUE)) {
      throw new \InvalidArgumentException('Unknown claim mode.');
    }
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

    // Never trust the caller: validate target, user, rights and window.
    if ($uid <= 0 || $entity_id <= 0) {
      throw new \InvalidArgumentException('A valid user and entity ID are required.');
    }
    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      throw new \InvalidArgumentException('The target entity cannot be claimed.');
    }
    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    if (!$entity || (int) $entity->id() !== $entity_id || !$this->isEntityTypeBundleEnabled($entity_type, $entity->bundle())) {
      throw new \InvalidArgumentException('The target entity cannot be claimed.');
    }
    if ($expires_at > 0 && $expires_at < $starts_at) {
      throw new \InvalidArgumentException('The end of the access window is before its start.');
    }
    $allowed_rights = $this->getAllowedRights();
    if (empty($allowed_rights)) {
      throw new \InvalidArgumentException('No access rights are currently claimable.');
    }
    $rights = array_values(array_unique(array_filter($rights)));
    if (empty($rights)) {
      $rights = $allowed_rights;
    }
    if (array_diff($rights, $allowed_rights)) {
      throw new \InvalidArgumentException('One or more requested rights are not claimable.');
    }
    $rights_str = implode(',', $rights);

    // The check-then-write sequences below must be atomic per entity, or two
    // simultaneous claims can both pass the exclusivity check.
    $grant_id = $this->withEntityLock($entity_type, $entity_id, function () use ($entity_type, $entity_id, $uid, $rights_str, $mode, $now, $starts_at, $expires_at, $notes, $submission_id): int {
      $transaction = $this->database->startTransaction();
      try {
        // The user's own active grant (exclusive/append) is refreshed in place.
        $existing = $mode === self::MODE_REPLACE ? FALSE : $this->database->select('claim_access_grants', 'c')
          ->fields('c', ['id'])
          ->condition('entity_type', $entity_type)
          ->condition('entity_id', $entity_id)
          ->condition('uid', $uid)
          ->condition('status', self::STATUS_ACTIVE)
          ->execute()
          ->fetchField();

        if ($mode === self::MODE_EXCLUSIVE) {
          // Exclude the user's own grant so it cannot mask someone else's
          // overlapping reservation.
          $conflict = $this->getOverlappingExclusiveGrant($entity_type, $entity_id, $starts_at, $expires_at, $existing ? (int) $existing : NULL);
          if ($conflict) {
            throw new \RuntimeException(sprintf(
              'Exclusive access is already reserved on %s ID %d for an overlapping duration.',
              $entity_type,
              $entity_id
            ));
          }
        }
        elseif ($mode === self::MODE_REPLACE) {
          $this->database->update('claim_access_grants')
            ->fields(['status' => self::STATUS_REPLACED])
            ->condition('entity_type', $entity_type)
            ->condition('entity_id', $entity_id)
            ->condition('status', self::STATUS_ACTIVE)
            ->execute();
        }

        if ($existing) {
          $grant_id = (int) $existing;
          $this->database->update('claim_access_grants')
            ->fields([
              'rights' => $rights_str,
              'starts_at' => $starts_at,
              'expires_at' => $expires_at,
              'mode' => $mode,
              'notes' => $notes,
              'submission_id' => $submission_id ?? 0,
              'extension_requested' => 0,
            ])
            ->condition('id', $grant_id)
            ->execute();
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
              'extension_requested' => 0,
            ])
            ->execute();
        }
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      return $grant_id;
    });

    $this->logger->notice('Granted claim access ID @id to UID @uid for @type @eid (Mode: @mode, Rights: @rights).', [
      '@id' => $grant_id,
      '@uid' => $uid,
      '@type' => $entity_type,
      '@eid' => $entity_id,
      '@mode' => $mode,
      '@rights' => $rights_str,
    ]);

    $this->invalidateGrantCaches($entity_type, $entity_id);

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
    $now = $this->time->getRequestTime();
    $starts_at = ($starts_at > 0) ? $starts_at : $now;

    $query = $this->database->select('claim_access_grants', 'c')
      ->fields('c')
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', $entity_id)
      ->condition('status', self::STATUS_ACTIVE)
      ->condition('mode', self::MODE_EXCLUSIVE);

    if ($exclude_grant_id) {
      $query->condition('id', $exclude_grant_id, '<>');
    }

    // Problem 2: Filter expired rows directly in SQL to prevent memory overhead.
    $query->condition($query->orConditionGroup()
      ->condition('expires_at', 0)
      ->condition('expires_at', $now, '>')
    );

    // Problem 6: Sargable index condition on date_range:
    // Existing grant must not finish before the requested interval starts.
    $query->condition($query->orConditionGroup()
      ->condition('expires_at', 0)
      ->condition('expires_at', $starts_at, '>')
    );

    // If requested interval has finite end, the existing grant must start
    // before the requested interval ends. Legacy rows with starts_at = 0 use
    // created as their effective start time.
    if ($expires_at > 0) {
      $query->condition($query->orConditionGroup()
        ->condition($query->andConditionGroup()
          ->condition('starts_at', 0, '>')
          ->condition('starts_at', $expires_at, '<')
        )
        ->condition($query->andConditionGroup()
          ->condition('starts_at', 0)
          ->condition('created', $expires_at, '<')
        )
      );
    }

    // The SQL predicates above fully express interval overlap, so only one
    // conflict is needed. Avoid materializing every matching grant.
    return $query
      ->range(0, 1)
      ->execute()
      ->fetchAssoc() ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function hasAccess(EntityInterface $entity, AccountInterface $account, string $op): bool {
    if ($account->isAnonymous() || !$entity->id()) {
      return FALSE;
    }

    if (!$this->isEntityTypeBundleEnabled($entity->getEntityTypeId(), $entity->bundle())) {
      return FALSE;
    }

    // Read-only: expiry is evaluated by comparing timestamps. Status changes
    // are left to cron (purgeExpiredGrants) so that page views never write.
    $record = $this->getUserGrantFor($entity->getEntityTypeId(), (int) $entity->id(), (int) $account->id());
    if (!$record || !$this->isGrantInEffect($record)) {
      return FALSE;
    }

    // Map operation to rights: 'update' maps to 'edit'.
    $required_right = match ($op) {
      'update' => self::RIGHT_EDIT,
      'view' => self::RIGHT_VIEW,
      default => $op,
    };

    return in_array($required_right, explode(',', (string) $record['rights']), TRUE);
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

    if (empty($this->getAllowedRights())) {
      return [
        'claimable' => FALSE,
        'reason' => 'No access rights are currently claimable.',
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
    $now = $this->time->getRequestTime();
    $query = $this->database->select('claim_access_grants', 'c')
      ->fields('c')
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', $entity_id)
      ->condition('status', self::STATUS_ACTIVE);

    $query->condition($query->orConditionGroup()
      ->condition('expires_at', 0)
      ->condition('expires_at', $now, '>')
    );

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
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
      ->fields([
        'status' => self::STATUS_REVOKED,
        'extension_requested' => 0,
      ])
      ->condition('id', $grant_id)
      ->execute();

    $this->invalidateGrantCaches((string) $grant['entity_type'], (int) $grant['entity_id']);

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
  public function extendGrant(int $grant_id, int $additional_days = 30, bool $reinstate = FALSE): bool {
    $grant = $this->getGrant($grant_id);
    if (!$grant || $additional_days < 1) {
      return FALSE;
    }

    // Grants that were revoked or replaced are only ever brought back by an
    // explicit administrator action, never by a plain extension.
    $ended = in_array($grant['status'], [self::STATUS_REVOKED, self::STATUS_REPLACED], TRUE);
    if ($ended && !$reinstate) {
      return FALSE;
    }

    $entity_type = (string) $grant['entity_type'];
    $entity_id = (int) $grant['entity_id'];

    $extended = $this->withEntityLock($entity_type, $entity_id, function () use ($grant_id, $additional_days, $reinstate): bool {
      // Re-read under the lock: the grant may have been revoked or replaced
      // while this request was waiting.
      $grant = $this->getGrant($grant_id);
      if (!$grant || (in_array($grant['status'], [self::STATUS_REVOKED, self::STATUS_REPLACED], TRUE) && !$reinstate)) {
        return FALSE;
      }
      $now = $this->time->getRequestTime();
      $current_expiry = (int) $grant['expires_at'];
      $base_time = ($current_expiry > $now) ? $current_expiry : $now;
      $new_expiry = $base_time + ($additional_days * 86400);
      $starts_at = (int) ($grant['starts_at'] ?: $grant['created']);

      if ((int) $grant['expires_at'] === 0 && $grant['status'] === self::STATUS_ACTIVE) {
        // Already permanent: nothing to extend, and never shorten it.
        return FALSE;
      }

      // Bringing an inactive replaced grant back must not create a second active holder.
      if ($grant['mode'] === self::MODE_REPLACE && $grant['status'] !== self::STATUS_ACTIVE) {
        foreach ($this->getActiveGrants((string) $grant['entity_type'], (int) $grant['entity_id']) as $other) {
          if ((int) $other['id'] !== $grant_id) {
            return FALSE;
          }
        }
      }
      if ($grant['mode'] === self::MODE_EXCLUSIVE) {
        $conflict = $this->getOverlappingExclusiveGrant((string) $grant['entity_type'], (int) $grant['entity_id'], $starts_at, $new_expiry, $grant_id);
        if ($conflict && (int) $conflict['uid'] !== (int) $grant['uid']) {
          return FALSE;
        }
      }

      $fields = [
        'expires_at' => $new_expiry,
        'status' => self::STATUS_ACTIVE,
        'extension_requested' => 0,
      ];
      $existing_notes = (string) ($grant['notes'] ?? '');
      if (self::isExtensionPending($grant)) {
        $now_date = date('Y-m-d H:i', $now);
        $fields['notes'] = trim($existing_notes . "\n" . "[{$now_date}] Extension approved: +{$additional_days} days.");
      }

      $this->database->update('claim_access_grants')
        ->fields($fields)
        ->condition('id', $grant_id)
        ->execute();
      return TRUE;
    });

    if (!$extended) {
      return FALSE;
    }

    $this->invalidateGrantCaches($entity_type, $entity_id);

    $this->logger->notice('Extended grant @id by @days days.', [
      '@id' => $grant_id,
      '@days' => $additional_days,
    ]);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function requestExtension(int $grant_id, int $additional_days = 30, ?string $reason = null): bool {
    $grant = $this->getGrant($grant_id);
    // Only live or lapsed grants can be extended by their holder. Revoked and
    // replaced grants were ended deliberately and stay ended.
    if (!$grant || !in_array($grant['status'], [self::STATUS_ACTIVE, self::STATUS_EXPIRED], TRUE)) {
      return FALSE;
    }
    $additional_days = max(1, min(90, $additional_days));

    $config = $this->configFactory->get('claim_access_rights.settings');
    $auto_approve = (bool) $config->get('user_extension_auto_approve');
    $existing_notes = (string) ($grant['notes'] ?? '');

    // Disallow submitting a new extension request if one is already pending.
    if (!$auto_approve && self::isExtensionPending($grant)) {
      return FALSE;
    }

    $reason = $reason !== null ? mb_substr(trim($reason), 0, 1000) : '';
    // Strip newlines and brackets to prevent spoofing system note line prefixes.
    $reason = preg_replace('/[\[\]\r\n]/', ' ', $reason);
    $reason = trim(preg_replace('/\s+/', ' ', $reason));

    $now = $this->time->getRequestTime();
    $now_date = date('Y-m-d H:i', $now);

    if ($auto_approve) {
      // Total lifetime of a self-service grant stays within max_claim_days.
      $max_days = (int) $config->get('max_claim_days');
      $starts_at = (int) ($grant['starts_at'] ?: $grant['created']);
      $expiry = (int) $grant['expires_at'];
      $new_expiry = (($expiry > $now) ? $expiry : $now) + ($additional_days * 86400);
      if ($max_days > 0 && $new_expiry > $starts_at + ($max_days * 86400)) {
        return FALSE;
      }
      if (!$this->extendGrant($grant_id, $additional_days)) {
        return FALSE;
      }
      $note_line = "[{$now_date}] Auto-approved extension: +{$additional_days} days." . ($reason ? " Reason: {$reason}" : '');
      $this->database->update('claim_access_grants')
        ->fields([
          'notes' => trim($existing_notes . "\n" . $note_line),
          'extension_requested' => 0,
        ])
        ->condition('id', $grant_id)
        ->execute();

      $this->logger->notice('User extension auto-approved for grant @id (+@days days).', [
        '@id' => $grant_id,
        '@days' => $additional_days,
      ]);
      return TRUE;
    }

    // Manual approval: record the request without modifying active status so
    // legitimate access is preserved during review. If the grant was already
    // expired, mark it as pending renewal.
    $new_status = ($grant['status'] === self::STATUS_EXPIRED) ? self::STATUS_PENDING : $grant['status'];
    $note_line = "[{$now_date}] Extension requested: +{$additional_days} days." . ($reason ? " Reason: {$reason}" : '');
    $this->database->update('claim_access_grants')
      ->fields([
        'status' => $new_status,
        'extension_requested' => 1,
        'notes' => trim($existing_notes . "\n" . $note_line),
      ])
      ->condition('id', $grant_id)
      ->execute();

    $this->invalidateGrantCaches((string) $grant['entity_type'], (int) $grant['entity_id']);

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

    $this->invalidateGrantCaches((string) $grant['entity_type'], (int) $grant['entity_id']);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteGrantsForEntity(string $entity_type, int $entity_id): int {
    $deleted = (int) $this->database->delete('claim_access_grants')
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', $entity_id)
      ->execute();

    if ($deleted > 0) {
      $this->invalidateGrantCaches($entity_type, $entity_id);
      $this->logger->info('Deleted @count claim access grant(s) for deleted entity @type #@id.', [
        '@count' => $deleted,
        '@type' => $entity_type,
        '@id' => $entity_id,
      ]);
    }

    return $deleted;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteGrantsForUser(int $uid): int {
    // Find affected entities to invalidate their cache tags.
    $rows = $this->database->select('claim_access_grants', 'c')
      ->fields('c', ['entity_type', 'entity_id'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $deleted = (int) $this->database->delete('claim_access_grants')
      ->condition('uid', $uid)
      ->execute();

    if ($deleted > 0) {
      $tags = ['claim_access_grants', 'user:' . $uid];
      foreach ($rows as $row) {
        $tags[] = self::entityTag((string) $row['entity_type'], (int) $row['entity_id']);
        $tags[] = $row['entity_type'] . ':' . $row['entity_id'];
      }
      $this->cacheTagsInvalidator->invalidateTags(array_unique($tags));
      $this->logger->info('Deleted @count claim access grant(s) for deleted user #@uid.', [
        '@count' => $deleted,
        '@uid' => $uid,
      ]);
    }

    return $deleted;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatistics(): array {
    $now = $this->time->getRequestTime();
    $soon = $now + (7 * 86400);

    $query = $this->database->select('claim_access_grants', 'c');
    $query->addField('c', 'status');
    $query->addField('c', 'entity_type');
    $query->addExpression('CASE WHEN c.expires_at = 0 THEN 1 ELSE 0 END', 'no_expiry');
    $query->addExpression('CASE WHEN c.expires_at > 0 AND c.expires_at <= :now_a THEN 1 ELSE 0 END', 'is_past', [':now_a' => $now]);
    $query->addExpression('CASE WHEN c.expires_at > :now_b AND c.expires_at <= :soon THEN 1 ELSE 0 END', 'is_soon', [':now_b' => $now, ':soon' => $soon]);
    $query->addExpression('COUNT(*)', 'cnt');
    $query->groupBy('c.status');
    $query->groupBy('c.entity_type');
    $query->groupBy('no_expiry');
    $query->groupBy('is_past');
    $query->groupBy('is_soon');

    $stats = [
      'total' => 0,
      'active' => 0,
      'pending' => 0,
      'no_expiry' => 0,
      'expiring_soon' => 0,
      'expired' => 0,
      'replaced' => 0,
      'revoked' => 0,
      'by_entity_type' => [],
    ];

    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $count = (int) $row['cnt'];
      $stats['total'] += $count;
      $stats['by_entity_type'][$row['entity_type']] = ($stats['by_entity_type'][$row['entity_type']] ?? 0) + $count;

      switch ($row['status']) {
        case self::STATUS_ACTIVE:
          if ((int) $row['is_past'] === 1) {
            $stats['expired'] += $count;
          }
          else {
            $stats['active'] += $count;
            if ((int) $row['no_expiry'] === 1) {
              $stats['no_expiry'] += $count;
            }
            elseif ((int) $row['is_soon'] === 1) {
              $stats['expiring_soon'] += $count;
            }
          }
          break;

        case self::STATUS_PENDING:
          $stats['pending'] += $count;
          break;

        case self::STATUS_EXPIRED:
          $stats['expired'] += $count;
          break;

        case self::STATUS_REPLACED:
          $stats['replaced'] += $count;
          break;

        case self::STATUS_REVOKED:
          $stats['revoked'] += $count;
          break;
      }
    }

    return $stats;
  }

  /**
   * {@inheritdoc}
   */
  public function purgeExpiredGrants(): int {
    $now = $this->time->getRequestTime();

    $rows = $this->database->select('claim_access_grants', 'c')
      ->fields('c', ['id', 'entity_type', 'entity_id'])
      ->condition('status', self::STATUS_ACTIVE)
      ->condition('expires_at', 0, '>')
      ->condition('expires_at', $now, '<=')
      ->range(0, 1000)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    if (!$rows) {
      return 0;
    }

    $updated = (int) $this->database->update('claim_access_grants')
      ->fields(['status' => self::STATUS_EXPIRED])
      ->condition('id', array_column($rows, 'id'), 'IN')
      ->condition('status', self::STATUS_ACTIVE)
      ->execute();

    // Access results are tagged per entity, so batch invalidate tags across all rows.
    $this->userGrantIndex = [];
    $tags = ['claim_access_grants'];
    foreach ($rows as $row) {
      $entity_type = (string) $row['entity_type'];
      $entity_id = (string) $row['entity_id'];
      $tags[] = $entity_type . ':' . $entity_id;
      $tags[] = self::entityTag($entity_type, $entity_id);
    }
    $this->cacheTagsInvalidator->invalidateTags(array_values(array_unique($tags)));
    $this->logger->info('Purged @count expired claim access grants.', ['@count' => $updated]);

    return $updated;
  }

  /**
   * {@inheritdoc}
   */
  public function validateClaimWindow(int $starts_at, int $expires_at): ?string {
    $config = $this->configFactory->get('claim_access_rights.settings');
    $now = $this->time->getRequestTime();
    $max_days = (int) $config->get('max_claim_days');

    if ($expires_at > 0 && $expires_at < $starts_at) {
      return (string) new TranslatableMarkup('Access end date must be on or after the start date.');
    }
    if ($expires_at === 0 && !$config->get('allow_permanent_claims')) {
      return (string) new TranslatableMarkup('Permanent access cannot be requested. Please choose an end date.');
    }
    if ($max_days > 0) {
      if ($starts_at > $now + ($max_days * 86400)) {
        return (string) new TranslatableMarkup('Access cannot be reserved more than @days days in advance.', ['@days' => $max_days]);
      }
      if ($expires_at > 0 && ($expires_at - $starts_at) > ($max_days * 86400)) {
        return (string) new TranslatableMarkup('Access can be requested for at most @days days at a time.', ['@days' => $max_days]);
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function validateUserClaimLimit(int $uid): ?string {
    $max = (int) $this->configFactory->get('claim_access_rights.settings')->get('max_active_claims_per_user');
    if ($max <= 0) {
      return NULL;
    }
    $now = $this->time->getRequestTime();
    $query = $this->database->select('claim_access_grants', 'c')
      ->condition('uid', $uid)
      ->condition('status', self::STATUS_ACTIVE);
    $query->condition($query->orConditionGroup()
      ->condition('expires_at', 0)
      ->condition('expires_at', $now, '>'));
    if ((int) $query->countQuery()->execute()->fetchField() >= $max) {
      return (string) new TranslatableMarkup('You have reached the maximum number of active claims (@max).', ['@max' => $max]);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessCacheMetadata(EntityInterface $entity, AccountInterface $account): array {
    $max_age = Cache::PERMANENT;
    if ($account->isAuthenticated() && $entity->id()) {
      $record = $this->getUserGrantFor($entity->getEntityTypeId(), (int) $entity->id(), (int) $account->id());
      if ($record) {
        $max_age = $this->getGrantsMaxAge([$record]);
      }
    }
    return [
      'tags' => [
        'config:claim_access_rights.settings',
        self::entityTag($entity->getEntityTypeId(), $entity->id() ?? 0),
      ],
      'max_age' => $max_age,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getGrantsMaxAge(array $grants): int {
    $now = $this->time->getRequestTime();
    $max_age = Cache::PERMANENT;
    foreach ($grants as $grant) {
      foreach ([(int) ($grant['starts_at'] ?: $grant['created']), (int) $grant['expires_at']] as $boundary) {
        if ($boundary > $now) {
          $delta = $boundary - $now;
          $max_age = ($max_age === Cache::PERMANENT) ? $delta : min($max_age, $delta);
        }
      }
    }
    // For time-limited grants, cap max-age at 3600 seconds (1 hour) as defense-in-depth
    // to bound staleness if a grant is mutated without tag invalidation.
    return ($max_age === Cache::PERMANENT) ? Cache::PERMANENT : min($max_age, 3600);
  }

  /**
   * Per-entity cache tag carried by every access result and claim banner.
   */
  public static function entityTag(string $entity_type, int|string $entity_id): string {
    return 'claim_access_grants:' . $entity_type . ':' . $entity_id;
  }

  /**
   * Returns the rights that may be claimed, from configuration.
   *
   * @return string[]
   */
  private function getAllowedRights(): array {
    $known = [self::RIGHT_VIEW, self::RIGHT_EDIT];
    $configured = $this->configFactory->get('claim_access_rights.settings')->get('allowed_rights');
    // If the setting was never configured at all (NULL), fallback to default known rights.
    if ($configured === NULL) {
      return $known;
    }
    // Misconfigured or empty configuration fails closed.
    return array_values(array_intersect($known, (array) $configured));
  }

  /**
   * Returns the user's active grant for an entity.
   *
   * This is intentionally a targeted lookup. Access checks run frequently, so
   * loading every active grant for the account would make the cost of one
   * entity access check grow with the user's total number of grants.
   */
  private function getUserGrantFor(string $entity_type, int $entity_id, int $uid): ?array {
    $key = $entity_type . ':' . $entity_id;
    if (isset($this->userGrantIndex[$uid]) && array_key_exists($key, $this->userGrantIndex[$uid])) {
      return $this->userGrantIndex[$uid][$key];
    }

    $now = $this->time->getRequestTime();
    $query = $this->database->select('claim_access_grants', 'c')
      ->fields('c')
      ->condition('uid', $uid)
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', $entity_id)
      ->condition('status', self::STATUS_ACTIVE);

    $query->condition($query->orConditionGroup()
      ->condition('expires_at', 0)
      ->condition('expires_at', $now, '>')
    );

    // Prefer the earliest grant window. This selects a current grant over a
    // future one when legacy data contains multiple active rows.
    $query->orderBy('starts_at', 'ASC');
    $query->orderBy('created', 'ASC');

    $row = $query->range(0, 1)->execute()->fetchAssoc();
    $this->userGrantIndex[$uid][$key] = $row ?: NULL;
    return $this->userGrantIndex[$uid][$key];
  }

  /**
   * {@inheritdoc}
   */
  public static function isExtensionPending(array $grant): bool {
    return !empty($grant['extension_requested']);
  }

  /**
   * Whether a grant's time window covers the current request time.
   */
  private function isGrantInEffect(array $record): bool {
    $now = $this->time->getRequestTime();
    $starts_at = (int) ($record['starts_at'] ?: $record['created']);
    $expires_at = (int) $record['expires_at'];
    return $starts_at <= $now && ($expires_at === 0 || $expires_at > $now);
  }

  /**
   * Runs a callback while holding a per-entity lock.
   *
   * Infrastructure Requirement:
   * Sites operating across multiple web servers or clustered PHP-FPM containers
   * must configure a shared lock backend (such as Drupal core's default database
   * semaphore or a shared Redis/Memcache lock backend) to serialize concurrent
   * claims across all server nodes.
   */
  private function withEntityLock(string $entity_type, int $entity_id, callable $callback): mixed {
    $name = 'claim_access_rights:' . $entity_type . ':' . $entity_id;
    if (!$this->lock->acquire($name, 5.0)) {
      throw new \RuntimeException('Could not obtain a lock for this item. Please try again.');
    }
    try {
      return $callback();
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * Clears the per-request index and invalidates all tags tied to a target.
   */
  private function invalidateGrantCaches(string $entity_type, int|string $entity_id): void {
    $this->userGrantIndex = [];
    $this->cacheTagsInvalidator->invalidateTags([
      $entity_type . ':' . $entity_id,
      'claim_access_grants',
      self::entityTag($entity_type, $entity_id),
    ]);
  }

}
