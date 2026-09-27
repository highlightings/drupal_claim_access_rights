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
 * Service managing claim access grants and permissions.
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
    ?int $submission_id = null
  ): int {
    $config = $this->configFactory->get('claim_access_rights.settings');
    $mode = $mode ?: (string) $config->get('claim_mode') ?: self::MODE_EXCLUSIVE;
    $now = $this->time->getRequestTime();

    // Calculate expiry if not explicitly passed.
    if ($expires_at === null) {
      $expiry_type = (string) $config->get('expiry_type') ?: 'days';
      $days = (int) $config->get('default_expiry_days') ?: 30;
      if ($expiry_type === 'days' && $days > 0) {
        $expires_at = $now + ($days * 86400);
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
        $active_grants = $this->getActiveGrants($entity_type, $entity_id);
        foreach ($active_grants as $grant) {
          if ((int) $grant['uid'] !== $uid) {
            throw new \RuntimeException(sprintf(
              'Exclusive access is already granted on %s ID %d to user ID %d.',
              $entity_type,
              $entity_id,
              $grant['uid']
            ));
          }
        }
        // If the same user has an active grant, update it.
        if (!empty($active_grants)) {
          $existing_id = (int) $active_grants[0]['id'];
          $this->database->update('claim_access_grants')
            ->fields([
              'rights' => $rights_str,
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

    // Invalidate entity cache and claims cache tag.
    $this->cacheTagsInvalidator->invalidateTags([
      $entity_type . ':' . $entity_id,
      'claim_access_grants',
    ]);

    return $grant_id;
  }

  /**
   * {@inheritdoc}
   */
  public function hasAccess(EntityInterface $entity, AccountInterface $account, string $op): bool {
    if ($account->isAnonymous() || !$entity->id()) {
      return FALSE;
    }

    // Check if the entity bundle is enabled for claiming.
    $config = $this->configFactory->get('claim_access_rights.settings');
    $enabled_bundles = (array) $config->get('enabled_bundles') ?: ['listing'];
    if (!in_array($entity->bundle(), $enabled_bundles, TRUE)) {
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

    // Check expiration.
    $expires_at = (int) $record['expires_at'];
    $now = $this->time->getRequestTime();
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
    $config = $this->configFactory->get('claim_access_rights.settings');
    $enabled_bundles = (array) $config->get('enabled_bundles') ?: ['listing'];

    if (!in_array($entity->bundle(), $enabled_bundles, TRUE)) {
      return [
        'claimable' => FALSE,
        'reason' => 'Claiming is not enabled for this content type.',
        'mode' => (string) $config->get('claim_mode') ?: self::MODE_EXCLUSIVE,
        'active_grants' => [],
      ];
    }

    $mode = (string) $config->get('claim_mode') ?: self::MODE_EXCLUSIVE;
    $active_grants = $this->getActiveGrants($entity->getEntityTypeId(), (int) $entity->id());

    if ($account && !$account->isAnonymous()) {
      foreach ($active_grants as $grant) {
        if ((int) $grant['uid'] === (int) $account->id()) {
          return [
            'claimable' => FALSE,
            'reason' => 'You already hold an active access grant for this listing.',
            'mode' => $mode,
            'active_grants' => $active_grants,
            'user_is_claimant' => TRUE,
            'grant' => $grant,
          ];
        }
      }
    }

    // If Exclusive mode and another user has an active grant, disable new claims.
    if ($mode === self::MODE_EXCLUSIVE && !empty($active_grants)) {
      return [
        'claimable' => FALSE,
        'reason' => 'This listing has already been claimed and exclusive access is active. New requests are currently disabled.',
        'mode' => $mode,
        'active_grants' => $active_grants,
      ];
    }

    return [
      'claimable' => TRUE,
      'reason' => 'This listing is available to claim.',
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
