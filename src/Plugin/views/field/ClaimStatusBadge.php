<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Plugin\views\field;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Views field handler to display grant status badges.
 *
 * @ViewsField("claim_status_badge")
 */
#[ViewsField("claim_status_badge")]
final class ClaimStatusBadge extends FieldPluginBase {

  public function query(): void {
    $this->ensureMyTable();
    $this->addAdditionalFields(['status', 'expires_at']);
  }

  public function render(ResultRow $values): array {
    $status_field = $this->aliases['status'] ?? 'status';
    $expires_at_field = $this->aliases['expires_at'] ?? 'expires_at';
    $status = (string) ($values->{$status_field} ?? $this->getValue($values, 'status'));
    $expires_at = (int) ($values->{$expires_at_field} ?? $this->getValue($values, 'expires_at'));
    $now = \Drupal::time()->getRequestTime();
    $seven_days = $now + (7 * 86400);

    $is_active = ($status === ClaimAccessManagerInterface::STATUS_ACTIVE && ($expires_at === 0 || $expires_at > $now));

    $badge = match (TRUE) {
      !$is_active && $status === 'replaced' => '<span style="background:#f3e8ff; color:#7e22ce; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:0.85em;">REPLACED</span>',
      !$is_active && $status === 'revoked' => '<span style="background:#fee2e2; color:#b91c1c; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:0.85em;">REVOKED</span>',
      !$is_active => '<span style="background:#f1f5f9; color:#475569; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:0.85em;">EXPIRED</span>',
      $expires_at > 0 && $expires_at <= $seven_days => '<span style="background:#fef3c7; color:#b45309; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:0.85em;">EXPIRING SOON</span>',
      default => '<span style="background:#dcfce7; color:#15803d; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:0.85em;">ACTIVE</span>',
    };

    return [
      '#markup' => $badge,
    ];
  }

}
