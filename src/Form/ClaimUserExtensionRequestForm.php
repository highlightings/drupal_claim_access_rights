<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Form;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Form allowing a claimant user to request an extension of their access rights.
 */
final class ClaimUserExtensionRequestForm extends FormBase {

  private ?array $grant = null;

  public function __construct(
    private readonly ClaimAccessManagerInterface $claimAccessManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('claim_access_rights.manager'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'claim_user_extension_request_form';
  }

  /**
   * Custom access check for extension request form.
   */
  public function access(AccountInterface $account, ?int $grant_id = null): AccessResultInterface {
    if (!$account->isAuthenticated()) {
      return AccessResult::forbidden()->cachePerUser();
    }

    if ($account->hasPermission('administer claim access rights')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $grant = $this->claimAccessManager->getGrant((int) $grant_id);
    if (!$grant) {
      return AccessResult::forbidden()->cachePerUser()->addCacheTags(['claim_access_grants']);
    }

    // Owners may only extend grants that are live or have lapsed. Revoked and
    // replaced grants were ended on purpose and must not be self-reinstated.
    $extendable = in_array($grant['status'], [
      ClaimAccessManagerInterface::STATUS_ACTIVE,
      ClaimAccessManagerInterface::STATUS_EXPIRED,
    ], TRUE);
    if ($extendable && (int) $account->id() === (int) $grant['uid'] && $account->hasPermission('claim access rights')) {
      return AccessResult::allowed()->cachePerUser()->addCacheTags(['claim_access_grants']);
    }

    return AccessResult::forbidden()->cachePerUser()->addCacheTags(['claim_access_grants']);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $grant_id = null): array {
    $this->grant = $this->claimAccessManager->getGrant((int) $grant_id);

    if (!$this->grant) {
      throw new NotFoundHttpException();
    }

    // Load target entity information for presentation.
    $entity_label = $this->grant['entity_type'] . ' #' . $this->grant['entity_id'];
    try {
      $entity = $this->entityTypeManager->getStorage($this->grant['entity_type'])->load($this->grant['entity_id']);
      if ($entity) {
        $entity_label = (string) ($entity->label() ?: $entity_label);
      }
    }
    catch (\Throwable) {}

    $exp_ts = (int) $this->grant['expires_at'];
    $now = \Drupal::time()->getRequestTime();
    $expiry_desc = $exp_ts === 0 ? $this->t('Permanent (Never expires)') : ($exp_ts <= $now ? $this->t('Expired @diff ago (@date)', ['@diff' => $this->dateFormatter->formatTimeDiffSince($exp_ts), '@date' => $this->dateFormatter->format($exp_ts, 'short')]) : $this->t('Active, expires in @diff (@date)', ['@diff' => $this->dateFormatter->formatTimeDiffUntil($exp_ts), '@date' => $this->dateFormatter->format($exp_ts, 'short')]));

    $form['summary_card'] = [
      '#type' => 'container',
      '#attributes' => [
        'style' => 'background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:18px 22px; margin-bottom:24px;',
      ],
    ];

    $form['summary_card']['heading'] = [
      '#markup' => sprintf(
        '<div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; letter-spacing:0.05em;">%s</div>' .
        '<h2 style="margin:4px 0 12px 0; font-size:20px; color:#0f172a;">%s</h2>' .
        '<div style="font-size:14px; color:#334155; line-height:1.6;">' .
        '<strong>%s:</strong> %s &nbsp;|&nbsp; <strong>%s:</strong> %s &nbsp;|&nbsp; <strong>%s:</strong> %s' .
        '</div>',
        htmlspecialchars(strtoupper($this->grant['entity_type']), ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($entity_label, ENT_QUOTES, 'UTF-8'),
        $this->t('Granted Rights'),
        htmlspecialchars(strtoupper($this->grant['rights']), ENT_QUOTES, 'UTF-8'),
        $this->t('Current Status'),
        htmlspecialchars(strtoupper($this->grant['status']), ENT_QUOTES, 'UTF-8'),
        $this->t('Expiration'),
        htmlspecialchars((string) $expiry_desc, ENT_QUOTES, 'UTF-8')
      ),
    ];

    $form['additional_days'] = [
      '#type' => 'select',
      '#title' => $this->t('Requested Extension Duration'),
      '#options' => [
        15 => $this->t('15 Days'),
        30 => $this->t('30 Days (Recommended)'),
        60 => $this->t('60 Days (2 Months)'),
        90 => $this->t('90 Days (3 Months)'),
      ],
      '#default_value' => 30,
      '#required' => TRUE,
      '#description' => $this->t('Select the additional duration you are requesting.'),
    ];

    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason for Extension Request'),
      '#description' => $this->t('Please provide a brief justification explaining why you need continued access rights for this content.'),
      '#required' => TRUE,
      '#rows' => 4,
      '#attributes' => [
        'placeholder' => $this->t('e.g., We are continuing to host events at this hall and need access to update upcoming schedules and pricing details...'),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit Extension Request'),
      '#button_type' => 'primary',
    ];

    $user_claims_url = Url::fromRoute('view.claim_access_grants.page_user_claims', ['user' => $this->grant['uid']]);
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $user_claims_url,
      '#attributes' => [
        'class' => ['button', 'button--secondary'],
        'style' => 'margin-left:12px;',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->grant) {
      return;
    }

    $grant_id = (int) $this->grant['id'];
    $days = (int) $form_state->getValue('additional_days');
    $reason = trim((string) $form_state->getValue('reason'));

    $success = $this->claimAccessManager->requestExtension($grant_id, $days, $reason);

    if ($success) {
      $updated_grant = $this->claimAccessManager->getGrant($grant_id);
      if ($updated_grant && $updated_grant['status'] === ClaimAccessManagerInterface::STATUS_ACTIVE) {
        $new_exp = (int) $updated_grant['expires_at'];
        $this->messenger()->addStatus($this->t('Your access extension of @days days has been approved! Your access is now active until @date.', [
          '@days' => $days,
          '@date' => $this->dateFormatter->format($new_exp, 'medium'),
        ]));
      }
      else {
        $this->messenger()->addStatus($this->t('Your extension request for @days days has been submitted and is pending review by site administrators.', [
          '@days' => $days,
        ]));
      }
    }
    else {
      $this->messenger()->addError($this->t('Unable to process extension request at this time.'));
    }

    $form_state->setRedirect('view.claim_access_grants.page_user_claims', ['user' => $this->grant['uid']]);
  }

}
