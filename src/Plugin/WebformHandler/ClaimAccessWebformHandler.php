<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Plugin\WebformHandler;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\claim_access_rights\ClaimSubmissionProcessor;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform handler for validating and routing claim access rights requests.
 *
 * @WebformHandler(
 *   id = "claim_access_rights_handler",
 *   label = @Translation("Claim Access Rights Handler"),
 *   category = @Translation("Access Control"),
 *   description = @Translation("Validates entity claim availability, checks exclusivity, and triggers automated or ECA approval."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
final class ClaimAccessWebformHandler extends WebformHandlerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ClaimAccessManagerInterface $claimAccessManager,
    private readonly ClaimSubmissionProcessor $processor,
    private readonly AccountProxyInterface $currentUser,
    EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
    MessengerInterface $messenger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entityTypeManager;
    $this->configFactory = $configFactory;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('claim_access_rights.manager'),
      $container->get('claim_access_rights.submission_processor'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission): void {
    parent::validateForm($form, $form_state, $webform_submission);

    // Only validate during final submission.
    if ($form_state->getErrors()) {
      return;
    }

    if ($this->currentUser->isAnonymous()) {
      $form_state->setErrorByName('target_entity_title', (string) $this->t('You must be logged in to claim access to a listing.'));
      return;
    }

    // Do not rely solely on Webform's access configuration: submissions can
    // also be created through alternate Webform entry points.
    if (!$this->currentUser->hasPermission('claim access rights')) {
      $form_state->setErrorByName('target_entity_title', (string) $this->t('You do not have permission to submit access claims.'));
      return;
    }

    $values = $form_state->getValues();
    $entity_id = (int) ($values['target_entity_id'] ?? 0);
    $entity_type = (string) ($values['target_entity_type'] ?? 'node');

    if ($entity_id <= 0) {
      $form_state->setErrorByName('target_entity_id', (string) $this->t('Please specify a valid listing to claim.'));
      return;
    }

    // The type comes from a client-controlled hidden field: never hand it to
    // getStorage() unchecked.
    $entity = $this->entityTypeManager->hasDefinition($entity_type)
      ? $this->entityTypeManager->getStorage($entity_type)->load($entity_id)
      : NULL;
    if (!$entity) {
      $form_state->setErrorByName('target_entity_id', (string) $this->t('The specified listing could not be found.'));
      return;
    }

    $check = $this->claimAccessManager->isClaimable($entity, $this->currentUser);
    if (!$check['claimable']) {
      $form_state->setErrorByName('target_entity_title', $check['reason']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE): void {
    parent::postSave($webform_submission, $update);

    // Only process new submissions.
    if ($update) {
      return;
    }

    // Defense in depth for programmatic/API-created submissions.
    if (!$this->currentUser->hasPermission('claim access rights')) {
      $this->messenger->addError($this->t('Your claim could not be processed because you do not have permission to submit access claims.'));
      return;
    }

    $config = $this->configFactory->get('claim_access_rights.settings');
    $mode = (string) $config->get('auto_approval_mode') ?: 'eca';

    if ($mode === 'immediate') {
      try {
        $this->processor->approve($webform_submission);
        $this->messenger->addStatus($this->t('Your claim has been immediately approved! You now have access rights to this listing.'));
      }
      catch (\InvalidArgumentException $e) {
        // Validation text is safe and specific, e.g. "maximum access window".
        $this->messenger->addError($this->t('Your claim could not be approved: @reason', ['@reason' => $e->getMessage()]));
        \Drupal::logger('claim_access_rights')->warning('Immediate claim rejected: @msg', ['@msg' => $e->getMessage()]);
      }
      catch (\Throwable $e) {
        // Never echo other exception text to the visitor: it can carry SQL,
        // internal paths or other users' reservation details.
        $this->messenger->addError($this->t('Your claim could not be approved right now. It may conflict with an existing reservation.'));
        \Drupal::logger('claim_access_rights')->error('Immediate claim failed: @msg', ['@msg' => $e->getMessage()]);
      }
    }
    elseif ($mode === 'eca') {
      // ECA processes the submission on entity insert.
      $this->messenger->addStatus($this->t('Your claim has been submitted and is being processed by the automated approval workflow.'));
    }
    else {
      $this->messenger->addStatus($this->t('Your claim has been submitted and is pending administrator review.'));
    }
  }

}
