<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Plugin\views\field;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Views field handler to render grant operations.
 *
 * @ViewsField("claim_operations_field")
 */
#[ViewsField("claim_operations_field")]
final class ClaimOperationsField extends FieldPluginBase {

  public function query(): void {
    $this->ensureMyTable();
    $this->addAdditionalFields(['id', 'status', 'expires_at', 'uid', 'notes', 'extension_requested']);
  }

  public function render(ResultRow $values): array {
    $id_field = $this->aliases['id'] ?? 'id';
    $status_field = $this->aliases['status'] ?? 'status';
    $expires_at_field = $this->aliases['expires_at'] ?? 'expires_at';
    $uid_field = $this->aliases['uid'] ?? 'uid';
    $notes_field = $this->aliases['notes'] ?? 'notes';
    $ext_field = $this->aliases['extension_requested'] ?? 'extension_requested';

    $grant_id = (int) ($values->{$id_field} ?? $this->getValue($values, 'id'));
    $status = (string) ($values->{$status_field} ?? $this->getValue($values, 'status'));
    $expires_at = (int) ($values->{$expires_at_field} ?? $this->getValue($values, 'expires_at'));
    $grant_uid = (int) ($values->{$uid_field} ?? $this->getValue($values, 'uid'));
    $notes = (string) ($values->{$notes_field} ?? $this->getValue($values, 'notes') ?? '');
    $extension_requested = (bool) ($values->{$ext_field} ?? $this->getValue($values, 'extension_requested') ?? FALSE);

    $current_account = \Drupal::currentUser();
    $is_admin = $current_account->hasPermission('administer claim access rights');
    $is_owner = ((int) $current_account->id() === $grant_uid);
    $now = \Drupal::time()->getRequestTime();

    $is_active = ($status === ClaimAccessManagerInterface::STATUS_ACTIVE && ($expires_at === 0 || $expires_at > $now));

    // Admin view: full management dropbutton.
    if ($is_admin) {
      $links = [];
      if ($is_active) {
        $links['revoke'] = [
          'title' => $this->t('Revoke'),
          'url' => Url::fromRoute('claim_access_rights.revoke_grant', ['grant_id' => $grant_id]),
        ];
        $links['extend'] = [
          'title' => $this->t('Extend (+30 Days)'),
          'url' => Url::fromRoute('claim_access_rights.extend_grant', ['grant_id' => $grant_id]),
        ];
      }
      else {
        $links['reinstate'] = [
          'title' => $this->t('Reinstate (+30 Days)'),
          'url' => Url::fromRoute('claim_access_rights.extend_grant', ['grant_id' => $grant_id]),
        ];
      }

      $links['request_extension'] = [
        'title' => $this->t('Custom Extension...'),
        'url' => Url::fromRoute('claim_access_rights.user_request_extension', ['grant_id' => $grant_id]),
      ];

      $links['delete'] = [
        'title' => $this->t('Delete'),
        'url' => Url::fromRoute('claim_access_rights.delete_grant', ['grant_id' => $grant_id]),
      ];

      return [
        '#type' => 'dropbutton',
        '#links' => $links,
      ];
    }

    // Claimant user view: Request Extension button or pending status.
    if ($is_owner) {
      if ($status === ClaimAccessManagerInterface::STATUS_PENDING || $extension_requested) {
        return [
          '#markup' => '<span style="background:#fef3c7; color:#b45309; padding:4px 10px; border-radius:4px; font-weight:700; font-size:11px;">PENDING REVIEW</span>',
        ];
      }

      if (in_array($status, [ClaimAccessManagerInterface::STATUS_REVOKED, ClaimAccessManagerInterface::STATUS_REPLACED], TRUE)) {
        return [
          '#markup' => '<span style="color:#94a3b8; font-size:12px;">Access Ended</span>',
        ];
      }

      // Active or Expired: allow requesting extension.
      return [
        '#type' => 'link',
        '#title' => $this->t('Request Extension'),
        '#url' => Url::fromRoute('claim_access_rights.user_request_extension', ['grant_id' => $grant_id]),
        '#attributes' => [
          'class' => ['button', 'button--small', 'button--primary'],
          'style' => 'background:#2563eb; color:#ffffff; padding:4px 12px; border-radius:4px; font-weight:600; text-decoration:none; display:inline-block; font-size:12px;',
        ],
      ];
    }

    return ['#markup' => '-'];
  }

}
