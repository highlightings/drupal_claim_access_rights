<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Approves a pending claim, creating the access grant.
 */
final class ClaimSubmissionApproveForm extends ClaimSubmissionDecisionFormBase {

  public function getFormId(): string {
    return 'claim_access_rights_submission_approve';
  }

  public function getQuestion() {
    return $this->t('Approve claim #@id?', ['@id' => $this->submission?->id() ?? 0]);
  }

  public function getDescription() {
    return $this->t('This grants the requested rights for the requested window, after re-checking that the item is still claimable and the limits are respected.');
  }

  public function getConfirmText() {
    return $this->t('Approve');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->submission) {
      try {
        $grant_id = $this->processor->approve($this->submission);
        $this->messenger()->addStatus($this->t('Claim #@id approved (grant #@grant).', ['@id' => $this->submission->id(), '@grant' => $grant_id]));
      }
      catch (\InvalidArgumentException | \RuntimeException $e) {
        // Shown to staff only (the route requires the administer permission).
        $this->messenger()->addError($this->t('Claim #@id could not be approved: @reason', ['@id' => $this->submission->id(), '@reason' => $e->getMessage()]));
      }
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
