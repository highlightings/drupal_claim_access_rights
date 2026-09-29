<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Plugin\Action;

use Drupal\claim_access_rights\ClaimSubmissionProcessor;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Config\ConfigFactoryInterface;
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
    private readonly ClaimSubmissionProcessor $processor,
    private readonly ConfigFactoryInterface $configFactory,
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
      $container->get('claim_access_rights.submission_processor'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($object = NULL): void {
    if (!$object instanceof WebformSubmissionInterface) {
      return;
    }

    // Only act when the site is configured for ECA approval. Otherwise the
    // immediate handler (or an administrator) owns the decision, and running
    // here as well would approve claims the admin chose to review manually.
    $mode = (string) $this->configFactory->get('claim_access_rights.settings')->get('auto_approval_mode') ?: 'eca';
    if ($mode !== 'eca') {
      return;
    }

    try {
      $this->processor->approve($object);
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      \Drupal::logger('claim_access_rights')->warning('Submission @id was not approved: @msg', [
        '@id' => $object->id(),
        '@msg' => $e->getMessage(),
      ]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('claim_access_rights')->error('Error in GrantClaimAccessAction: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    // Grant only for submissions whose owner is allowed to claim.
    $owner = $object instanceof WebformSubmissionInterface ? $object->getOwner() : NULL;
    $result = ($owner && $owner->hasPermission('claim access rights'))
      ? \Drupal\Core\Access\AccessResult::allowed()
      : \Drupal\Core\Access\AccessResult::forbidden();
    $result->addCacheContexts(['user.permissions']);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
