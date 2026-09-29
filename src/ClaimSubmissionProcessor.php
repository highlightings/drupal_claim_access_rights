<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Turns a claim webform submission into a grant, applying every safety check.
 *
 * This is the single approval path shared by the ECA action, the immediate
 * handler and the manual approval screen, so the rules cannot drift apart.
 */
final class ClaimSubmissionProcessor {

  public const WEBFORM_ID = 'claim_listing';

  public function __construct(
    private readonly ClaimAccessManagerInterface $manager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Extracts the claim parameters from a submission.
   *
   * @return array<string, mixed>|null
   *   NULL when the dates are missing or unparsable. A blank end date is not a
   *   request for permanent access; only the explicit checkbox is.
   */
  public function parse(WebformSubmissionInterface $submission): ?array {
    $data = $submission->getData();
    $rights = $data['requested_rights'] ?? [ClaimAccessManagerInterface::RIGHT_VIEW, ClaimAccessManagerInterface::RIGHT_EDIT];
    if (is_string($rights)) {
      $rights = explode(',', $rights);
    }

    $starts_at = !empty($data['start_date'])
      ? strtotime((string) $data['start_date'])
      : (int) $submission->getCreatedTime();
    if (!empty($data['no_end_date'])) {
      $expires_at = 0;
    }
    elseif (!empty($data['end_date'])) {
      $expires_at = strtotime((string) $data['end_date'] . ' 23:59:59');
    }
    else {
      $expires_at = FALSE;
    }
    if ($starts_at === FALSE || $expires_at === FALSE) {
      return NULL;
    }

    return [
      'entity_type' => (string) ($data['target_entity_type'] ?? 'node'),
      'entity_id' => (int) ($data['target_entity_id'] ?? 0),
      'uid' => (int) $submission->getOwnerId(),
      'rights' => array_values(array_filter((array) $rights)),
      'notes' => (string) ($data['claim_notes'] ?? ''),
      'starts_at' => (int) $starts_at,
      'expires_at' => (int) $expires_at,
      'submission_id' => (int) $submission->id(),
    ];
  }

  /**
   * Approves a submission by creating the grant.
   *
   * @return int
   *   The grant ID.
   *
   * @throws \InvalidArgumentException
   *   When the claim fails validation. The message is safe to show to staff.
   * @throws \RuntimeException
   *   When an exclusive reservation conflicts or the lock cannot be taken.
   */
  public function approve(WebformSubmissionInterface $submission): int {
    $webform = $submission->getWebform();
    if (!$webform || $webform->id() !== self::WEBFORM_ID) {
      throw new \InvalidArgumentException('This submission is not an access claim.');
    }
    $claim = $this->parse($submission);
    if ($claim === NULL || $claim['entity_id'] <= 0 || $claim['uid'] <= 0) {
      throw new \InvalidArgumentException('The submission has missing or invalid dates, target or owner.');
    }

    // Submissions can be created outside the form (API, imports), so nothing
    // the form validated is taken on trust here.
    $owner = $submission->getOwner();
    if (!$owner || !$owner->hasPermission('claim access rights')) {
      throw new \InvalidArgumentException('The submitting user is not allowed to claim access.');
    }
    $entity = $this->entityTypeManager->hasDefinition($claim['entity_type'])
      ? $this->entityTypeManager->getStorage($claim['entity_type'])->load($claim['entity_id'])
      : NULL;
    if (!$entity) {
      throw new \InvalidArgumentException('The target item no longer exists.');
    }
    $info = $this->manager->isClaimable($entity, $owner);
    if (empty($info['claimable'])) {
      throw new \InvalidArgumentException((string) ($info['reason'] ?? 'The target item cannot be claimed.'));
    }

    // The window and per-user-limit checks are not repeated here: grantAccess()
    // re-validates both itself, the limit check atomically under its user lock,
    // so this stays a single source of truth instead of two copies that could
    // drift apart.
    return $this->manager->grantAccess(
      $claim['entity_type'],
      $claim['entity_id'],
      $claim['uid'],
      $claim['rights'],
      NULL,
      $claim['expires_at'],
      $claim['notes'],
      $claim['submission_id'],
      $claim['starts_at'],
    );
  }

}
