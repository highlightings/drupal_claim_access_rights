<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Form;

use Drupal\claim_access_rights\ClaimSubmissionProcessor;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared behaviour of the approve and reject confirmation forms.
 */
abstract class ClaimSubmissionDecisionFormBase extends ConfirmFormBase {

  protected ?WebformSubmissionInterface $submission = NULL;

  public function __construct(
    protected readonly ClaimSubmissionProcessor $processor,
    protected readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('claim_access_rights.submission_processor'),
      $container->get('database'),
    );
  }

  /**
   * Only staff, only claim submissions, and only ones still awaiting a decision.
   */
  public function access(AccountInterface $account, ?WebformSubmissionInterface $webform_submission = NULL): AccessResultInterface {
    if (!$account->hasPermission('administer claim access rights') || !$webform_submission) {
      return AccessResult::forbidden()->cachePerPermissions();
    }
    $pending = $webform_submission->getWebform()->id() === ClaimSubmissionProcessor::WEBFORM_ID
      && !$webform_submission->isLocked()
      && !$this->database->select('claim_access_grants', 'g')
        ->condition('submission_id', (int) $webform_submission->id())
        ->countQuery()->execute()->fetchField();
    return AccessResult::allowedIf($pending)
      ->cachePerPermissions()
      ->addCacheableDependency($webform_submission)
      ->addCacheTags(['claim_access_grants']);
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('claim_access_rights.pending');
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?WebformSubmissionInterface $webform_submission = NULL): array {
    $this->submission = $webform_submission;
    return parent::buildForm($form, $form_state);
  }

}
