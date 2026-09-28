<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Plugin\views\field;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Views field handler to display relative and formatted expiration.
 *
 * @ViewsField("claim_expiry_badge")
 */
final class ClaimExpiryBadge extends FieldPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('date.formatter')
    );
  }

  public function render(ResultRow $values): array {
    $exp_ts = (int) $this->getValue($values);
    $now = \Drupal::time()->getRequestTime();
    $seven_days = $now + (7 * 86400);

    if ($exp_ts === 0) {
      return ['#markup' => '<span style="color:#64748b;">Permanent</span>'];
    }

    if ($exp_ts <= $now) {
      $text = sprintf('<span style="color:#dc2626; font-weight:600;">Expired %s ago</span><br><small style="color:#64748b;">%s</small>',
        $this->dateFormatter->formatTimeDiffSince($exp_ts),
        $this->dateFormatter->format($exp_ts, 'short')
      );
    }
    elseif ($exp_ts <= $seven_days) {
      $text = sprintf('<span style="color:#d97706; font-weight:700;">Expires in %s</span><br><small style="color:#64748b;">%s</small>',
        $this->dateFormatter->formatTimeDiffUntil($exp_ts),
        $this->dateFormatter->format($exp_ts, 'short')
      );
    }
    else {
      $text = sprintf('<span>in %s</span><br><small style="color:#64748b;">%s</small>',
        $this->dateFormatter->formatTimeDiffUntil($exp_ts),
        $this->dateFormatter->format($exp_ts, 'short')
      );
    }

    return ['#markup' => $text];
  }

}
