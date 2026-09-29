<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Rejects a pending claim. The submission is locked so it leaves the queue.
 */
final class ClaimSubmissionRejectForm extends ClaimSubmissionDecisionFormBase {

  public function getFormId(): string {
    return 'claim_access_rights_submission_reject';
  }

  public function getQuestion() {
    return $this->t('Reject claim #@id?', ['@id' => $this->submission?->id() ?? 0]);
  }

  public function getDescription() {
    return $this->t('The claim is closed without granting access. The submission is kept for your records.');
  }

  public function getConfirmText() {
    return $this->t('Reject');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->submission) {
      $this->submission->setLocked(TRUE)->save();
      \Drupal::logger('claim_access_rights')->notice('Claim submission @id rejected by UID @uid.', [
        '@id' => $this->submission->id(),
        '@uid' => $this->currentUser()->id(),
      ]);
      $this->messenger()->addStatus($this->t('Claim #@id rejected.', ['@id' => $this->submission->id()]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
