<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights\Plugin\views\filter;

use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\filter\InOperator;
use Drupal\views\ViewExecutable;

/**
 * Filter handler to filter claims by indefinite (no expiry) vs expiring access.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("claim_expiry_type")
 */
#[ViewsFilter("claim_expiry_type")]
final class ClaimExpiryTypeFilter extends InOperator {

  /**
   * {@inheritdoc}
   */
  protected $valueFormType = 'select';

  /**
   * {@inheritdoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, ?array &$options = NULL): void {
    parent::init($view, $display, $options);
    $this->valueTitle = (string) $this->t('Expiry Type');
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions(): array {
    if (isset($this->valueOptions)) {
      return $this->valueOptions;
    }

    $this->valueOptions = [
      'no_expiry' => (string) $this->t('Indefinite (No Expiration)'),
      'has_expiry' => (string) $this->t('Has Expiration Date'),
    ];

    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   */
  protected function opSimple(): void {
    if (empty($this->value)) {
      return;
    }
    $this->ensureMyTable();

    $values = (array) $this->value;
    $selected = array_values(array_filter($values, static fn($v) => $v !== '' && $v !== 'All'));

    if (empty($selected) || count($selected) === 2) {
      return;
    }

    $is_no_expiry = in_array('no_expiry', $selected, TRUE);
    if ($this->operator === 'not in') {
      $is_no_expiry = !$is_no_expiry;
    }

    if ($is_no_expiry) {
      $this->query->addWhere($this->options['group'], "$this->tableAlias.expires_at", 0, '=');
    }
    else {
      $this->query->addWhere($this->options['group'], "$this->tableAlias.expires_at", 0, '>');
    }
  }

}
