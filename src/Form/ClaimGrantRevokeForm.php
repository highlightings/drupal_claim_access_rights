<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Form;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for revoking a claim access grant.
 */
final class ClaimGrantRevokeForm extends ConfirmFormBase {

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
    return 'claim_access_grant_revoke_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to revoke grant #@id?', ['@id' => $this->grant['id'] ?? 0]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return new Url('claim_access_rights.claims_list');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $grant_id = null): array {
    $this->grant = $this->claimAccessManager->getGrant((int) $grant_id);
    if (!$this->grant) {
      $this->messenger()->addError($this->t('Grant not found.'));
      return $this->redirect('claim_access_rights.claims_list');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->grant) {
      $this->claimAccessManager->revokeGrant((int) $this->grant['id']);
      $this->messenger()->addStatus($this->t('Grant #@id has been revoked.', ['@id' => $this->grant['id']]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
