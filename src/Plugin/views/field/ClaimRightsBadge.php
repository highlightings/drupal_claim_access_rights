<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Views field handler to display rights badges.
 *
 * @ViewsField("claim_rights_badge")
 */
final class ClaimRightsBadge extends FieldPluginBase {

  public function render(ResultRow $values): array {
    $value = (string) $this->getValue($values);
    if (empty($value)) {
      return ['#markup' => '-'];
    }

    $rights = explode(',', $value);
    $html = '';
    foreach ($rights as $rt) {
      $color = (trim($rt) === 'edit') ? 'background:#e0f2fe; color:#0369a1;' : 'background:#f1f5f9; color:#334155;';
      $html .= sprintf('<span style="%s padding:2px 6px; border-radius:3px; font-size:0.8em; margin-right:4px; font-weight:600;">%s</span>', $color, strtoupper(htmlspecialchars(trim($rt), ENT_QUOTES, 'UTF-8')));
    }

    return [
      '#markup' => $html,
    ];
  }

}
