<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Controller;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for the Sitewide Access Claims Dashboard.
 */
final class ClaimsAdminController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
    private readonly ClaimAccessManagerInterface $claimAccessManager,
    private readonly EntityTypeManagerInterface $entityTypeManagerService,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('claim_access_rights.manager'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  /**
   * Displays the sitewide access claims dashboard.
   */
  public function listClaims(): array {
    $request = $this->requestStack->getCurrentRequest();
    $status_filter = $request?->query->get('status');
    $type_filter = $request?->query->get('type');

    $stats = $this->claimAccessManager->getStatistics();
    $now = \Drupal::time()->getRequestTime();
    $seven_days = $now + (7 * 86400);

    // Build metric cards.
    $build['dashboard_stats'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['claims-stats-grid'],
        'style' => 'display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;',
      ],
    ];

    $stat_cards = [
      [
        'label' => $this->t('Active Claims'),
        'count' => $stats['active'],
        'color' => '#16a34a',
        'bg' => '#f0fdf4',
        'filter' => 'active',
      ],
      [
        'label' => $this->t('Expiring Soon (< 7 Days)'),
        'count' => $stats['expiring_soon'],
        'color' => '#d97706',
        'bg' => '#fffbeb',
        'filter' => 'expiring_soon',
      ],
      [
        'label' => $this->t('Expired Claims'),
        'count' => $stats['expired'],
        'color' => '#64748b',
        'bg' => '#f8fafc',
        'filter' => 'expired',
      ],
      [
        'label' => $this->t('Replaced / Revoked'),
        'count' => $stats['replaced'] + $stats['revoked'],
        'color' => '#dc2626',
        'bg' => '#fef2f2',
        'filter' => 'inactive',
      ],
    ];

    foreach ($stat_cards as $card) {
      $card_url = Url::fromRoute('claim_access_rights.claims_list', [], [
        'query' => array_filter(['status' => $card['filter'], 'type' => $type_filter]),
      ])->toString();

      $build['dashboard_stats'][] = [
        '#markup' => sprintf(
          '<a href="%s" style="text-decoration:none; color:inherit; display:block; padding: 18px; border-radius: 10px; background: %s; border: 1px solid %s22; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: transform 0.15s ease;">
             <div style="font-size: 0.85em; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">%s</div>
             <div style="font-size: 2.1em; font-weight: 700; color: %s; margin-top: 4px;">%d</div>
           </a>',
          $card_url,
          $card['bg'],
          $card['color'],
          $card['label'],
          $card['color'],
          $card['count']
        ),
      ];
    }

    // Filter pills.
    $filter_links = [
      '' => $this->t('All Claims (@c)', ['@c' => $stats['total']]),
      'active' => $this->t('Active (@c)', ['@c' => $stats['active']]),
      'expiring_soon' => $this->t('Expiring Soon (@c)', ['@c' => $stats['expiring_soon']]),
      'expired' => $this->t('Expired (@c)', ['@c' => $stats['expired']]),
      'inactive' => $this->t('Replaced/Revoked (@c)', ['@c' => $stats['replaced'] + $stats['revoked']]),
    ];

    $pills_html = '<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; align-items: center;">';
    $pills_html .= '<span style="font-size: 0.9em; font-weight: bold; color: #475569;">Filter Status:</span>';
    foreach ($filter_links as $key => $title) {
      $is_active = ($status_filter === $key || ($key === '' && empty($status_filter)));
      $url = Url::fromRoute('claim_access_rights.claims_list', [], [
        'query' => array_filter(['status' => $key, 'type' => $type_filter]),
      ])->toString();
      $style = $is_active
        ? 'background: #2563eb; color: #fff; font-weight: 600;'
        : 'background: #f1f5f9; color: #334155;';
      $pills_html .= sprintf(
        '<a href="%s" style="padding: 6px 14px; border-radius: 20px; text-decoration: none; font-size: 0.88em; %s">%s</a>',
        $url,
        $style,
        $title
      );
    }

    // Entity type pills if multiple exist.
    if (count($stats['by_entity_type']) > 1) {
      $pills_html .= '<span style="margin-left: 16px; font-size: 0.9em; font-weight: bold; color: #475569;">Entity Type:</span>';
      $all_type_url = Url::fromRoute('claim_access_rights.claims_list', [], [
        'query' => array_filter(['status' => $status_filter]),
      ])->toString();
      $pills_html .= sprintf('<a href="%s" style="padding: 6px 14px; border-radius: 20px; text-decoration: none; font-size: 0.88em; %s">All</a>',
        $all_type_url,
        empty($type_filter) ? 'background: #475569; color: #fff;' : 'background: #f1f5f9; color: #334155;'
      );

      foreach ($stats['by_entity_type'] as $etype => $cnt) {
        $t_url = Url::fromRoute('claim_access_rights.claims_list', [], [
          'query' => array_filter(['status' => $status_filter, 'type' => $etype]),
        ])->toString();
        $is_selected = ($type_filter === $etype);
        $pills_html .= sprintf(
          '<a href="%s" style="padding: 6px 14px; border-radius: 20px; text-decoration: none; font-size: 0.88em; %s">%s (%d)</a>',
          $t_url,
          $is_selected ? 'background: #475569; color: #fff;' : 'background: #f1f5f9; color: #334155;',
          ucfirst(str_replace('_', ' ', $etype)),
          $cnt
        );
      }
    }
    $pills_html .= '</div>';

    $build['filters'] = ['#markup' => $pills_html];

    // Build Table Query.
    $query = $this->database->select('claim_access_grants', 'c')
      ->fields('c')
      ->orderBy('created', 'DESC');

    if ($type_filter) {
      $query->condition('entity_type', $type_filter);
    }

    if ($status_filter === 'active') {
      $query->condition('status', ClaimAccessManagerInterface::STATUS_ACTIVE);
      $query->condition(
        $query->orConditionGroup()
          ->condition('expires_at', 0)
          ->condition('expires_at', $now, '>')
      );
    }
    elseif ($status_filter === 'expiring_soon') {
      $query->condition('status', ClaimAccessManagerInterface::STATUS_ACTIVE)
        ->condition('expires_at', $now, '>')
        ->condition('expires_at', $seven_days, '<=');
    }
    elseif ($status_filter === 'expired') {
      $query->condition(
        $query->orConditionGroup()
          ->condition('status', ClaimAccessManagerInterface::STATUS_EXPIRED)
          ->condition(
            $query->andConditionGroup()
              ->condition('expires_at', 0, '>')
              ->condition('expires_at', $now, '<=')
          )
      );
    }
    elseif ($status_filter === 'inactive') {
      $query->condition('status', [ClaimAccessManagerInterface::STATUS_REPLACED, ClaimAccessManagerInterface::STATUS_REVOKED], 'IN');
    }

    $records = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $header = [
      'id' => $this->t('ID'),
      'type' => $this->t('Entity Type'),
      'target' => $this->t('Target Item'),
      'claimant' => $this->t('Claimant'),
      'rights' => $this->t('Rights'),
      'mode' => $this->t('Mode'),
      'status' => $this->t('Status'),
      'claimed_on' => $this->t('Claimed On'),
      'expires_at' => $this->t('Expires'),
      'notes' => $this->t('Reason / Note'),
      'operations' => $this->t('Operations'),
    ];

    $rows = [];
    foreach ($records as $r) {
      $entity_type = (string) $r['entity_type'];
      $entity_id = (int) $r['entity_id'];

      // Load entity dynamically.
      $entity = null;
      if ($this->entityTypeManagerService->hasDefinition($entity_type)) {
        $entity = $this->entityTypeManagerService->getStorage($entity_type)->load($entity_id);
      }

      $type_badge = match ($entity_type) {
        'node' => '<span style="background:#e0e7ff; color:#3730a3; padding:2px 8px; border-radius:4px; font-size:0.8em; font-weight:bold;">NODE</span>',
        'block_content' => '<span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:4px; font-size:0.8em; font-weight:bold;">BLOCK</span>',
        'media' => '<span style="background:#e0f2fe; color:#0369a1; padding:2px 8px; border-radius:4px; font-size:0.8em; font-weight:bold;">MEDIA</span>',
        'taxonomy_term' => '<span style="background:#f3e8ff; color:#6b21a8; padding:2px 8px; border-radius:4px; font-size:0.8em; font-weight:bold;">TERM</span>',
        default => sprintf('<span style="background:#f1f5f9; color:#475569; padding:2px 8px; border-radius:4px; font-size:0.8em;">%s</span>', strtoupper($entity_type)),
      };

      if ($entity) {
        $entity_label = $entity->label() ?: ($entity_type . ' #' . $entity_id);
        $entity_cell = $entity->hasLinkTemplate('canonical')
          ? Link::fromTextAndUrl($entity_label, $entity->toUrl())->toString()
          : htmlspecialchars($entity_label);
      }
      else {
        $entity_cell = sprintf('<em>%s #%d (deleted)</em>', htmlspecialchars($entity_type), $entity_id);
      }

      // Claimant.
      $user = $this->entityTypeManagerService->getStorage('user')->load((int) $r['uid']);
      $user_cell = $user
        ? Link::fromTextAndUrl($user->getDisplayName(), $user->toUrl())->toString() . '<br><span style="color:#64748b; font-size:0.8em;">' . htmlspecialchars($user->getEmail() ?: '') . '</span>'
        : 'User #' . $r['uid'];

      // Expiry & Status.
      $exp_ts = (int) $r['expires_at'];
      $is_active = ($r['status'] === ClaimAccessManagerInterface::STATUS_ACTIVE && ($exp_ts === 0 || $exp_ts > $now));

      if ($exp_ts === 0) {
        $expires_cell = '<span style="color:#64748b;">Permanent</span>';
      }
      elseif ($exp_ts <= $now) {
        $expires_cell = '<span style="color:#dc2626;">Expired ' . $this->dateFormatter->formatTimeDiffSince($exp_ts) . ' ago</span>';
      }
      elseif ($exp_ts <= $seven_days) {
        $expires_cell = '<span style="color:#d97706; font-weight:bold;">Expires in ' . $this->dateFormatter->formatTimeDiffUntil($exp_ts) . '</span><br><small style="color:#64748b;">' . $this->dateFormatter->format($exp_ts, 'short') . '</small>';
      }
      else {
        $expires_cell = '<span>in ' . $this->dateFormatter->formatTimeDiffUntil($exp_ts) . '</span><br><small style="color:#64748b;">' . $this->dateFormatter->format($exp_ts, 'short') . '</small>';
      }

      $status_badge = match (TRUE) {
        !$is_active && $r['status'] === 'replaced' => '<span style="background:#f3e8ff; color:#7e22ce; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:0.85em;">REPLACED</span>',
        !$is_active && $r['status'] === 'revoked' => '<span style="background:#fee2e2; color:#b91c1c; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:0.85em;">REVOKED</span>',
        !$is_active => '<span style="background:#f1f5f9; color:#475569; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:0.85em;">EXPIRED</span>',
        $exp_ts > 0 && $exp_ts <= $seven_days => '<span style="background:#fef3c7; color:#b45309; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:0.85em;">EXPIRING SOON</span>',
        default => '<span style="background:#dcfce7; color:#15803d; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:0.85em;">ACTIVE</span>',
      };

      // Rights badges.
      $rights_list = explode(',', (string) $r['rights']);
      $rights_html = '';
      foreach ($rights_list as $rt) {
        $color = ($rt === 'edit') ? 'background:#e0f2fe; color:#0369a1;' : 'background:#f1f5f9; color:#334155;';
        $rights_html .= sprintf('<span style="%s padding:2px 6px; border-radius:3px; font-size:0.8em; margin-right:4px; font-weight:600;">%s</span>', $color, strtoupper($rt));
      }

      // Operations.
      $operations = [];
      if ($is_active) {
        $operations['revoke'] = [
          'title' => $this->t('Revoke'),
          'url' => Url::fromRoute('claim_access_rights.revoke_grant', ['grant_id' => $r['id']]),
        ];
        $operations['extend'] = [
          'title' => $this->t('Extend (+30 Days)'),
          'url' => Url::fromRoute('claim_access_rights.extend_grant', ['grant_id' => $r['id']]),
        ];
      }
      else {
        $operations['reinstate'] = [
          'title' => $this->t('Reinstate (+30 Days)'),
          'url' => Url::fromRoute('claim_access_rights.extend_grant', ['grant_id' => $r['id']]),
        ];
      }
      $operations['delete'] = [
        'title' => $this->t('Delete'),
        'url' => Url::fromRoute('claim_access_rights.delete_grant', ['grant_id' => $r['id']]),
      ];

      $notes_text = (string) ($r['notes'] ?? '');
      if (strlen($notes_text) > 60) {
        $notes_text = substr($notes_text, 0, 57) . '...';
      }

      $rows[] = [
        'id' => $r['id'],
        'type' => ['data' => ['#markup' => $type_badge]],
        'target' => ['data' => ['#markup' => $entity_cell]],
        'claimant' => ['data' => ['#markup' => $user_cell]],
        'rights' => ['data' => ['#markup' => $rights_html]],
        'mode' => ucfirst($r['mode']),
        'status' => ['data' => ['#markup' => $status_badge]],
        'claimed_on' => $this->dateFormatter->format((int) $r['created'], 'short'),
        'expires_at' => ['data' => ['#markup' => $expires_cell]],
        'notes' => ['data' => ['#markup' => htmlspecialchars($notes_text)]],
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ],
        ],
      ];
    }

    $build['claims_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No claim access grants match the selected criteria.'),
      '#attributes' => [
        'class' => ['claims-dashboard-table'],
        'style' => 'width: 100%; border-collapse: separate; border-spacing: 0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-radius: 8px; overflow: hidden;',
      ],
    ];

    return $build;
  }

}
