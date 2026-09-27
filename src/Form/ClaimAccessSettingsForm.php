<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Form;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure settings for Claim Access Rights.
 */
final class ClaimAccessSettingsForm extends ConfigFormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new self(
      $container->get('entity_type.manager'),
    );
    $instance->setConfigFactory($container->get('config.factory'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['claim_access_rights.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'claim_access_rights_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('claim_access_rights.settings');

    $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $bundle_options = [];
    foreach ($node_types as $bundle => $type) {
      $bundle_options[$bundle] = $type->label();
    }

    $form['enabled_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled Content Types for Claiming'),
      '#description' => $this->t('Select which content types can be claimed by users.'),
      '#options' => $bundle_options,
      '#default_value' => (array) $config->get('enabled_bundles') ?: ['listing'],
    ];

    $form['claim_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Claim Access Mode'),
      '#description' => $this->t('Define how access claims are handled when multiple users make a claim:'),
      '#options' => [
        ClaimAccessManagerInterface::MODE_EXCLUSIVE => $this->t('<strong>Exclusive access</strong> — Once access is given to someone, new requests are disabled.'),
        ClaimAccessManagerInterface::MODE_REPLACE => $this->t('<strong>Replace access</strong> — Approving a new request will revoke/replace the old claimant from the access list.'),
        ClaimAccessManagerInterface::MODE_APPEND => $this->t('<strong>Append access</strong> — Approving a new request will append the user to the access list. Everyone will get access.'),
      ],
      '#default_value' => $config->get('claim_mode') ?: ClaimAccessManagerInterface::MODE_EXCLUSIVE,
    ];

    $form['allowed_rights'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Claimable Rights'),
      '#description' => $this->t('Select the rights that can be claimed by users.'),
      '#options' => [
        ClaimAccessManagerInterface::RIGHT_VIEW => $this->t('View access — View the node content'),
        ClaimAccessManagerInterface::RIGHT_EDIT => $this->t('Edit access — Edit and update the node'),
      ],
      '#default_value' => (array) $config->get('allowed_rights') ?: ['view', 'edit'],
    ];

    $form['expiry_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Access Expiry Configuration'),
    ];

    $form['expiry_fieldset']['expiry_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Access Duration / Expiration Type'),
      '#options' => [
        'none' => $this->t('No expiration (permanent access until revoked)'),
        'days' => $this->t('Access expires after a specified number of days'),
      ],
      '#default_value' => $config->get('expiry_type') ?: 'days',
    ];

    $form['expiry_fieldset']['default_expiry_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Default Expiry (Days)'),
      '#description' => $this->t('Number of days before granted access expires. E.g., 30 days.'),
      '#default_value' => $config->get('default_expiry_days') ?? 30,
      '#min' => 1,
      '#states' => [
        'visible' => [
          ':input[name="expiry_type"]' => ['value' => 'days'],
        ],
      ],
    ];

    $form['auto_approval_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Approval Workflow'),
      '#description' => $this->t('Choose how claims are approved and access granted:'),
      '#options' => [
        'eca' => $this->t('<strong>Automated via ECA</strong> — Use Event-Condition-Action model for flexible automated approval rules.'),
        'immediate' => $this->t('<strong>Immediate Auto-approval</strong> — Automatically grant access directly upon claim submission.'),
        'manual' => $this->t('<strong>Manual Approval</strong> — Site administrators manually review and grant access from the Claims Dashboard.'),
      ],
      '#default_value' => $config->get('auto_approval_mode') ?: 'eca',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('claim_access_rights.settings')
      ->set('enabled_bundles', array_values(array_filter($form_state->getValue('enabled_bundles'))))
      ->set('claim_mode', (string) $form_state->getValue('claim_mode'))
      ->set('allowed_rights', array_values(array_filter($form_state->getValue('allowed_rights'))))
      ->set('expiry_type', (string) $form_state->getValue('expiry_type'))
      ->set('default_expiry_days', (int) $form_state->getValue('default_expiry_days'))
      ->set('auto_approval_mode', (string) $form_state->getValue('auto_approval_mode'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
