<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Plugin\views\field;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\Core\Url;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Views field handler to render grant operations.
 *
 * @ViewsField("claim_operations_field")
 */
final class ClaimOperationsField extends FieldPluginBase {

  public function query(): void {
    $this->ensureMyTable();
    $this->addAdditionalFields(['id', 'status', 'expires_at']);
  }

  public function render(ResultRow $values): array {
    $id_field = $this->aliases['id'] ?? 'id';
    $status_field = $this->aliases['status'] ?? 'status';
    $expires_at_field = $this->aliases['expires_at'] ?? 'expires_at';
    $grant_id = (int) ($values->{$id_field} ?? $this->getValue($values, 'id'));
    $status = (string) ($values->{$status_field} ?? $this->getValue($values, 'status'));
    $expires_at = (int) ($values->{$expires_at_field} ?? $this->getValue($values, 'expires_at'));
    $now = \Drupal::time()->getRequestTime();

    $is_active = ($status === ClaimAccessManagerInterface::STATUS_ACTIVE && ($expires_at === 0 || $expires_at > $now));

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

    $links['delete'] = [
      'title' => $this->t('Delete'),
      'url' => Url::fromRoute('claim_access_rights.delete_grant', ['grant_id' => $grant_id]),
    ];

    return [
      '#type' => 'dropbutton',
      '#links' => $links,
    ];
  }

}
