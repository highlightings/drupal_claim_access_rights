<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Plugin\views\area;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Views area handler to display KPI metrics header.
 *
 * @ViewsArea("claim_stats_area")
 */
final class ClaimStatsArea extends AreaPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ClaimAccessManagerInterface $claimAccessManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('claim_access_rights.manager')
    );
  }

  public function render($empty = FALSE): array {
    $stats = $this->claimAccessManager->getStatistics();

    $cards = [
      [
        'label' => $this->t('Active Claims'),
        'count' => $stats['active'],
        'color' => '#15803d',
        'bg' => '#f0fdf4',
        'border' => '#bbf7d0',
      ],
      [
        'label' => $this->t('Expiring Soon (< 7 Days)'),
        'count' => $stats['expiring_soon'],
        'color' => '#b45309',
        'bg' => '#fefce8',
        'border' => '#fef08a',
      ],
      [
        'label' => $this->t('Expired Claims'),
        'count' => $stats['expired'],
        'color' => '#64748b',
        'bg' => '#f8fafc',
        'border' => '#e2e8f0',
      ],
      [
        'label' => $this->t('Replaced / Revoked'),
        'count' => $stats['replaced'] + $stats['revoked'],
        'color' => '#b91c1c',
        'bg' => '#fef2f2',
        'border' => '#fecaca',
      ],
    ];

    $html = '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">';
    foreach ($cards as $c) {
      $html .= sprintf(
        '<div style="background:%s; border:1px solid %s; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">' .
        '<div style="font-size:12px; font-weight:700; text-transform:uppercase; color:%s; letter-spacing:0.05em;">%s</div>' .
        '<div style="font-size:28px; font-weight:800; color:%s; margin-top:4px;">%d</div>' .
        '</div>',
        $c['bg'], $c['border'], $c['color'], $c['label'], $c['color'], $c['count']
      );
    }
    $html .= '</div>';

    return [
      '#markup' => $html,
      '#attached' => [
        'library' => ['core/drupal.dropbutton'],
      ],
    ];
  }

}
