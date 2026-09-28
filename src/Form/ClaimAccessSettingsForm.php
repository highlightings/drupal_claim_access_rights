<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Form;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure settings for Claim Access Rights across all content entity types.
 */
final class ClaimAccessSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
    );
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
    $enabled_entity_types = (array) $config->get('enabled_entity_types');

    $form['entity_types_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Claimable Content Entity Types & Bundles'),
      '#description' => $this->t('Select which entity types (Nodes, Blocks, Media, Taxonomy) can be claimed by users.'),
      '#open' => TRUE,
    ];

    $supported_entity_types = [
      'node' => $this->t('Content Types (Nodes)'),
      'block_content' => $this->t('Custom Content Blocks (Banners, Promo Tiles, Spotlights)'),
      'media' => $this->t('Media (Images, Videos, Documents)'),
      'taxonomy_term' => $this->t('Taxonomy Terms (Categories, Vocabularies)'),
    ];

    foreach ($supported_entity_types as $type_id => $type_label) {
      if (!$this->entityTypeManager->hasDefinition($type_id)) {
        continue;
      }

      $bundles = $this->bundleInfo->getBundleInfo($type_id);
      $bundle_options = [];
      foreach ($bundles as $b_id => $b_info) {
        $bundle_options[$b_id] = $b_info['label'] ?? $b_id;
      }

      if (empty($bundle_options)) {
        continue;
      }

      $defaults = $enabled_entity_types[$type_id] ?? [];
      // Backward compatibility for node legacy config.
      if (empty($defaults) && $type_id === 'node') {
        $defaults = (array) $config->get('enabled_bundles') ?: ['listing'];
      }

      $form['entity_types_container'][$type_id] = [
        '#type' => 'checkboxes',
        '#title' => $type_label,
        '#options' => $bundle_options,
        '#default_value' => (array) $defaults,
      ];
    }

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
        ClaimAccessManagerInterface::RIGHT_VIEW => $this->t('View access — View the entity content'),
        ClaimAccessManagerInterface::RIGHT_EDIT => $this->t('Edit access — Edit and update the entity'),
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
    $supported = ['node', 'block_content', 'media', 'taxonomy_term'];
    $enabled_entity_types = [];

    foreach ($supported as $type_id) {
      $val = $form_state->getValue($type_id);
      if (is_array($val)) {
        $selected = array_values(array_filter($val));
        if (!empty($selected)) {
          $enabled_entity_types[$type_id] = $selected;
        }
      }
    }

    $this->config('claim_access_rights.settings')
      ->set('enabled_entity_types', $enabled_entity_types)
      ->set('enabled_bundles', $enabled_entity_types['node'] ?? [])
      ->set('claim_mode', (string) $form_state->getValue('claim_mode'))
      ->set('allowed_rights', array_values(array_filter($form_state->getValue('allowed_rights'))))
      ->set('expiry_type', (string) $form_state->getValue('expiry_type'))
      ->set('default_expiry_days', (int) $form_state->getValue('default_expiry_days'))
      ->set('auto_approval_mode', (string) $form_state->getValue('auto_approval_mode'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
