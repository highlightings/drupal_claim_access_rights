<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Controller;

use Drupal\claim_access_rights\ClaimSubmissionProcessor;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists claim submissions that have not been approved or rejected yet.
 */
final class PendingClaimsController extends ControllerBase implements ContainerInjectionInterface {

  private const PER_PAGE = 25;

  public function __construct(
    private readonly Connection $database,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  public function list(): array {
    // Pending = a claim submission with no grant yet that nobody has locked
    // (rejecting a claim locks its submission). Joining in SQL keeps paging
    // correct instead of filtering a page after the fact.
    $query = $this->database->select('webform_submission', 'w')
      ->extend(PagerSelectExtender::class)
      ->limit(self::PER_PAGE);
    $query->leftJoin('claim_access_grants', 'g', 'g.submission_id = w.sid');
    $query->fields('w', ['sid']);
    $query->condition('w.webform_id', ClaimSubmissionProcessor::WEBFORM_ID);
    $query->condition('w.locked', 0);
    $query->isNull('g.id');
    $query->orderBy('w.created', 'DESC');
    $sids = $query->execute()->fetchCol();

    $build['intro'] = [
      '#markup' => '<p>' . $this->t('Claims waiting for a decision. Approving creates the access grant, using the same checks as automatic approval.') . '</p>',
    ];

    $rows = [];
    $submissions = $sids
      ? $this->entityTypeManager()->getStorage('webform_submission')->loadMultiple($sids)
      : [];
    foreach ($submissions as $submission) {
      $data = $submission->getData();
      $entity_type = (string) ($data['target_entity_type'] ?? 'node');
      $entity_id = (int) ($data['target_entity_id'] ?? 0);
      $target = $entity_type . ' #' . $entity_id;
      if ($this->entityTypeManager()->hasDefinition($entity_type) && $entity_id > 0) {
        $entity = $this->entityTypeManager()->getStorage($entity_type)->load($entity_id);
        if ($entity) {
          $target = (string) ($entity->label() ?: $target);
        }
      }
      $rights = (array) ($data['requested_rights'] ?? []);
      $window = !empty($data['no_end_date'])
        ? $this->t('@start – permanent', ['@start' => (string) ($data['start_date'] ?? '')])
        : $this->t('@start – @end', ['@start' => (string) ($data['start_date'] ?? ''), '@end' => (string) ($data['end_date'] ?? '')]);
      $owner = $submission->getOwner();

      $rows[] = [
        $this->dateFormatter->format((int) $submission->getCreatedTime(), 'short'),
        $owner ? $owner->toLink()->toRenderable() : $this->t('Unknown'),
        $target,
        implode(', ', array_filter($rights)),
        $window,
        (string) ($data['claim_notes'] ?? ''),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'approve' => [
                'title' => $this->t('Approve'),
                'url' => \Drupal\Core\Url::fromRoute('claim_access_rights.pending_approve', ['webform_submission' => $submission->id()]),
              ],
              'reject' => [
                'title' => $this->t('Reject'),
                'url' => \Drupal\Core\Url::fromRoute('claim_access_rights.pending_reject', ['webform_submission' => $submission->id()]),
              ],
            ],
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Submitted'),
        $this->t('Claimant'),
        $this->t('Item'),
        $this->t('Rights'),
        $this->t('Requested window'),
        $this->t('Note'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No claims are waiting for review.'),
      '#cache' => ['tags' => ['webform_submission_list', 'claim_access_grants'], 'contexts' => ['user.permissions']],
    ];
    $build['pager'] = ['#type' => 'pager'];

    return $build;
  }

}
