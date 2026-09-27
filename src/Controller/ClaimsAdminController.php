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

/**
 * Controller for managing claim access rights grants.
 */
final class ClaimsAdminController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
    private readonly ClaimAccessManagerInterface $claimAccessManager,
    private readonly EntityTypeManagerInterface $entityTypeManagerService,
    private readonly DateFormatterInterface $dateFormatter,
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
    );
  }

  /**
   * Displays the list of all claims and access grants.
   */
  public function listClaims(): array {
    $header = [
      'id' => $this->t('ID'),
      'entity' => $this->t('Target Listing / Node'),
      'claimant' => $this->t('Claimant'),
      'rights' => $this->t('Granted Rights'),
      'mode' => $this->t('Mode'),
      'status' => $this->t('Status'),
      'created' => $this->t('Claimed On'),
      'expires' => $this->t('Expires At'),
      'operations' => $this->t('Operations'),
    ];

    $records = $this->database->select('claim_access_grants', 'c')
      ->fields('c')
      ->orderBy('created', 'DESC')
      ->range(0, 50)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($records as $r) {
      $node = $this->entityTypeManagerService->getStorage('node')->load((int) $r['entity_id']);
      $entity_cell = $node
        ? Link::fromTextAndUrl($node->label(), $node->toUrl())->toString()
        : $this->t('Node #@id (deleted)', ['@id' => $r['entity_id']]);

      $user = $this->entityTypeManagerService->getStorage('user')->load((int) $r['uid']);
      $user_cell = $user
        ? Link::fromTextAndUrl($user->getDisplayName(), $user->toUrl())->toString()
        : $this->t('User #@id', ['@id' => $r['uid']]);

      $expires = (int) $r['expires_at'] > 0
        ? $this->dateFormatter->format((int) $r['expires_at'], 'short')
        : $this->t('Never');

      $status_class = match ($r['status']) {
        'active' => 'color: green; font-weight: bold;',
        'expired' => 'color: orange;',
        'revoked', 'replaced' => 'color: red;',
        default => '',
      };
      $status_cell = [
        'data' => [
          '#markup' => sprintf('<span style="%s">%s</span>', $status_class, strtoupper($r['status'])),
        ],
      ];

      $operations = [];
      if ($r['status'] === 'active') {
        $operations['revoke'] = [
          'title' => $this->t('Revoke'),
          'url' => Url::fromRoute('claim_access_rights.revoke_grant', ['grant_id' => $r['id']]),
        ];
      }

      $rows[] = [
        'id' => $r['id'],
        'entity' => ['data' => ['#markup' => $entity_cell]],
        'claimant' => ['data' => ['#markup' => $user_cell]],
        'rights' => strtoupper($r['rights']),
        'mode' => ucfirst($r['mode']),
        'status' => $status_cell,
        'created' => $this->dateFormatter->format((int) $r['created'], 'short'),
        'expires' => $expires,
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No claims have been submitted yet.'),
    ];
  }

}
