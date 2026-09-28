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

  public function query(): void {
    $this->ensureMyTable();
    $this->addAdditionalFields(['starts_at', 'expires_at']);
  }

  public function render(ResultRow $values): array {
    $expires_at_field = $this->aliases['expires_at'] ?? 'expires_at';
    $starts_at_field = $this->aliases['starts_at'] ?? 'starts_at';

    $exp_ts = (int) ($values->{$expires_at_field} ?? $this->getValue($values, 'expires_at'));
    $starts_at = (int) ($values->{$starts_at_field} ?? $this->getValue($values, 'starts_at'));

    $now = \Drupal::time()->getRequestTime();
    $seven_days = $now + (7 * 86400);

    $start_prefix = '';
    if ($starts_at > 0) {
      if ($starts_at > $now) {
        $start_prefix = sprintf('<span style="color:#d97706; font-weight:600; font-size:11px;">Starts: %s</span><br>', $this->dateFormatter->format($starts_at, 'custom', 'M j, Y'));
      }
      else {
        $start_prefix = sprintf('<small style="color:#64748b;">From: %s</small><br>', $this->dateFormatter->format($starts_at, 'custom', 'M j, Y'));
      }
    }

    if ($exp_ts === 0) {
      return [
        '#markup' => $start_prefix . '<span style="background:#ede9fe; color:#6d28d9; border:1px solid #c4b5fd; padding:3px 10px; border-radius:12px; font-weight:700; font-size:11px; letter-spacing:0.04em; display:inline-block;">♾ NO EXPIRY (INDEFINITE)</span>',
      ];
    }

    if ($exp_ts <= $now) {
      $text = $start_prefix . sprintf('<span style="color:#dc2626; font-weight:600;">Expired %s ago</span><br><small style="color:#64748b;">Until: %s</small>',
        $this->dateFormatter->formatTimeDiffSince($exp_ts),
        $this->dateFormatter->format($exp_ts, 'short')
      );
    }
    elseif ($exp_ts <= $seven_days) {
      $text = $start_prefix . sprintf('<span style="color:#d97706; font-weight:700;">Expires in %s</span><br><small style="color:#64748b;">Until: %s</small>',
        $this->dateFormatter->formatTimeDiffUntil($exp_ts),
        $this->dateFormatter->format($exp_ts, 'short')
      );
    }
    else {
      $text = $start_prefix . sprintf('<span>in %s</span><br><small style="color:#64748b;">Until: %s</small>',
        $this->dateFormatter->formatTimeDiffUntil($exp_ts),
        $this->dateFormatter->format($exp_ts, 'short')
      );
    }

    return ['#markup' => $text];
  }

}
