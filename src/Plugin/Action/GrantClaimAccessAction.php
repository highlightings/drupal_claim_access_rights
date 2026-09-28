<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Plugin\Action;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Grants claim access rights based on a webform submission or entity context.
 *
 * @Action(
 *   id = "claim_access_rights_grant",
 *   label = @Translation("Grant claim access rights"),
 *   type = "webform_submission"
 * )
 */
#[Action(
  id: 'claim_access_rights_grant',
  label: new TranslatableMarkup('Grant claim access rights'),
  type: 'webform_submission'
)]
final class GrantClaimAccessAction extends ActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ClaimAccessManagerInterface $claimAccessManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($object = NULL): void {
    if (!$object instanceof WebformSubmissionInterface) {
      return;
    }

    $data = $object->getData();
    $entity_id = (int) ($data['target_entity_id'] ?? 0);
    $entity_type = (string) ($data['target_entity_type'] ?? 'node');
    $uid = (int) $object->getOwnerId();

    if ($entity_id <= 0 || $uid <= 0) {
      return;
    }

    $rights = $data['requested_rights'] ?? ['view', 'edit'];
    if (is_string($rights)) {
      $rights = explode(',', $rights);
    }
    $rights = array_values(array_filter((array) $rights));

    $notes = (string) ($data['claim_notes'] ?? '');
    $submission_id = (int) $object->id();

    $start_date_str = (string) ($data['start_date'] ?? '');
    $no_end_date = !empty($data['no_end_date']);
    $end_date_str = (string) ($data['end_date'] ?? '');

    $now = \Drupal::time()->getRequestTime();
    $starts_at = !empty($start_date_str) ? strtotime($start_date_str) : $now;
    if ($no_end_date) {
      $expires_at = 0;
    }
    elseif (!empty($end_date_str)) {
      $expires_at = strtotime($end_date_str . ' 23:59:59');
    }
    else {
      $expires_at = null;
    }

    try {
      $this->claimAccessManager->grantAccess(
        $entity_type,
        $entity_id,
        $uid,
        $rights,
        null,
        $expires_at,
        $notes,
        $submission_id,
        $starts_at
      );
    }
    catch (\Throwable $e) {
      \Drupal::logger('claim_access_rights')->error('Error in GrantClaimAccessAction: @msg', [
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $return_as_object ? \Drupal\Core\Access\AccessResult::allowed() : TRUE;
  }

}
