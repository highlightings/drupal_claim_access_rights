<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Plugin\views\field;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Views field handler to display the target entity title and type badge.
 *
 * @ViewsField("claim_entity_field")
 */
#[ViewsField("claim_entity_field")]
final class ClaimEntityField extends FieldPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  public function query(): void {
    $this->ensureMyTable();
    $this->addAdditionalFields(['entity_type', 'entity_id']);
  }

  public function render(ResultRow $values): array {
    $entity_type_field = $this->aliases['entity_type'] ?? 'entity_type';
    $entity_id_field = $this->aliases['entity_id'] ?? 'entity_id';
    $entity_type = (string) ($values->{$entity_type_field} ?? $this->getValue($values, 'entity_type'));
    $entity_id = (int) ($values->{$entity_id_field} ?? $this->getValue($values, 'entity_id'));

    if (empty($entity_type) || empty($entity_id)) {
      return ['#markup' => '<em>' . $this->t('Unknown Entity') . '</em>'];
    }

    $badge_type = strtoupper($entity_type === 'block_content' ? 'block' : ($entity_type === 'taxonomy_term' ? 'term' : $entity_type));
    $type_badge = sprintf('<span style="display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700; background:#e0e7ff; color:#3730a3; margin-right:6px;">[%s]</span>', htmlspecialchars($badge_type, ENT_QUOTES, 'UTF-8'));

    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $entity = $storage->load($entity_id);
      if ($entity) {
        $label = $entity->label() ?: ($entity_type . ' #' . $entity_id);
        if ($entity->hasLinkTemplate('canonical') && $entity->access('view')) {
          $link = sprintf('<a href="%s" target="_blank" style="font-weight:600; color:#1e40af; text-decoration:none;">%s</a>', htmlspecialchars($entity->toUrl()->toString(), ENT_QUOTES, 'UTF-8'), htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8'));
        }
        else {
          $link = sprintf('<span style="font-weight:600; color:#1f2937;">%s</span>', htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8'));
        }
        return [
          '#markup' => $type_badge . ' ' . $link,
        ];
      }
    }
    catch (\Throwable) {
      // Fallback if entity cannot be loaded.
    }

    return [
      '#markup' => $type_badge . ' ' . htmlspecialchars($entity_type . ' #' . $entity_id, ENT_QUOTES, 'UTF-8'),
    ];
  }

}
