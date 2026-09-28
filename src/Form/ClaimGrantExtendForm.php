<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Form;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for extending a claim access grant.
 */
final class ClaimGrantExtendForm extends ConfirmFormBase {

  private ?array $grant = null;

  public function __construct(
    private readonly ClaimAccessManagerInterface $claimAccessManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('claim_access_rights.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'claim_access_grant_extend_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Extend grant #@id by 30 days?', ['@id' => $this->grant['id'] ?? 0]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will extend the active access duration for this user by 30 days and reinstate active status if expired.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('view.claim_access_grants.page_1');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $grant_id = null): array {
    $this->grant = $this->claimAccessManager->getGrant((int) $grant_id);
    if (!$this->grant) {
      $this->messenger()->addError($this->t('Grant not found.'));
      return $this->redirect('view.claim_access_grants.page_1');
    }

    $form['additional_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to extend'),
      '#default_value' => 30,
      '#min' => 1,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->grant) {
      $days = (int) $form_state->getValue('additional_days') ?: 30;
      $this->claimAccessManager->extendGrant((int) $this->grant['id'], $days);
      $this->messenger()->addStatus($this->t('Grant #@id has been extended by @days days.', [
        '@id' => $this->grant['id'],
        '@days' => $days,
      ]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
