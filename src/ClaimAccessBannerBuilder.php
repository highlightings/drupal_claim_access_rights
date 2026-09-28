<?php

declare(strict_types=1);

namespace Drupal\claim_access_rights;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Lazy builder for claim access callout and management banners.
 */
final class ClaimAccessBannerBuilder implements TrustedCallbackInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly ClaimAccessManagerInterface $claimAccessManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['buildBanner'];
  }

  /**
   * Builds the claim access banner for an entity.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   *
   * @return array
   *   A structured render array for the banner.
   */
  public function buildBanner(string $entity_type, int $entity_id): array {
    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      return [];
    }

    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    if (!$entity || !$this->claimAccessManager->isEntityTypeBundleEnabled($entity_type, $entity->bundle())) {
      return [];
    }

    $info = $this->claimAccessManager->isClaimable($entity, $this->currentUser);
    $type_label = ucfirst(str_replace('_', ' ', $entity_type));
    $entity_label = (string) ($entity->label() ?: ($entity_type . ' #' . $entity_id));

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['claim-access-entity-box'],
        'style' => 'margin: 20px 0; padding: 16px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc;',
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => [
          'config:claim_access_rights.settings',
          ClaimAccessManager::entityTag($entity_type, $entity_id),
        ],
        'max-age' => $this->claimAccessManager->getGrantsMaxAge($info['active_grants'] ?? []),
      ],
    ];

    $inner = [
      '#type' => 'container',
      '#attributes' => [
        'style' => 'display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;',
      ],
    ];

    if (!empty($info['user_is_claimant'])) {
      $grant = $info['grant'];
      $expires = (int) $grant['expires_at'] > 0
        ? $this->dateFormatter->format((int) $grant['expires_at'], 'short')
        : (string) $this->t('Never');

      $inner['text'] = [
        '#type' => 'container',
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $this->t('✓ You manage this @type', ['@type' => $type_label]),
          '#attributes' => ['style' => 'color:#166534; font-size:1.05em; display:block; margin-bottom:4px;'],
        ],
        'details' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Granted Rights: @rights | Expires: @expires', [
            '@rights' => strtoupper((string) $grant['rights']),
            '@expires' => $expires,
          ]),
          '#attributes' => ['style' => 'color:#64748b; font-size:0.9em;'],
        ],
      ];

      if (in_array('edit', explode(',', (string) $grant['rights']), TRUE) && $entity->hasLinkTemplate('edit-form')) {
        $inner['action'] = [
          '#type' => 'link',
          '#title' => $this->t('Edit @type', ['@type' => $type_label]),
          '#url' => $entity->toUrl('edit-form'),
          '#attributes' => [
            'class' => ['button', 'button--primary'],
            'style' => 'background:#2563eb; color:#fff; padding:8px 16px; border-radius:6px; text-decoration:none; font-weight:600;',
          ],
        ];
      }
    }
    elseif (!empty($info['permanently_claimed'])) {
      $inner['text'] = [
        '#type' => 'container',
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $this->t('Exclusive Access Granted'),
          '#attributes' => ['style' => 'color:#475569; display:block; margin-bottom:4px;'],
        ],
        'details' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('This @type has been claimed indefinitely under exclusive access. New access requests cannot be accepted.', [
            '@type' => strtolower($type_label),
          ]),
          '#attributes' => ['style' => 'color:#64748b; font-size:0.9em;'],
        ],
      ];
      $inner['action'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => (string) $this->t('Claiming Unavailable'),
        '#attributes' => [
          'disabled' => 'disabled',
          'class' => ['button', 'button--disabled'],
          'style' => 'background:#cbd5e1; color:#64748b; padding:8px 18px; border-radius:6px; font-weight:bold; border:1px solid #94a3b8; cursor:not-allowed;',
          'title' => (string) $this->t('Claiming is not possible for content with indefinite exclusive access.'),
        ],
      ];
    }
    elseif ($this->currentUser->isAnonymous()) {
      $destination = $entity->hasLinkTemplate('canonical') ? $entity->toUrl()->toString() : '';
      $login_url = Url::fromRoute('user.login', [], ['query' => $destination ? ['destination' => $destination] : []]);

      $inner['text'] = [
        '#type' => 'container',
        'prompt' => [
          '#markup' => '<strong>' . $this->t('Want to manage this @type?', ['@type' => strtolower($type_label)]) . '</strong> ',
        ],
        'login' => [
          '#type' => 'link',
          '#title' => $this->t('Log in to claim access'),
          '#url' => $login_url,
          '#attributes' => ['style' => 'color:#2563eb; font-weight:bold;'],
        ],
      ];
    }
    elseif ($info['claimable']) {
      $claim_url = Url::fromRoute('entity.webform.canonical', ['webform' => 'claim_listing'], [
        'query' => [
          'target_entity_type' => $entity_type,
          'target_entity_id' => (string) $entity_id,
          'target_entity_title' => $entity_label,
        ],
      ]);

      $notice_text = !empty($info['has_exclusive_windows'])
        ? $this->t('Exclusive access is active for specific dates. You can claim access for non-overlapping dates.')
        : $this->t('Claim view and edit rights to manage details and updates.');

      $inner['text'] = [
        '#type' => 'container',
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $this->t('Is this your @type?', ['@type' => strtolower($type_label)]),
          '#attributes' => ['style' => 'display:block; margin-bottom:4px;'],
        ],
        'details' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $notice_text,
          '#attributes' => ['style' => 'color:#64748b; font-size:0.9em;'],
        ],
      ];
      $inner['action'] = [
        '#type' => 'link',
        '#title' => $this->t('Claim This @type', ['@type' => $type_label]),
        '#url' => $claim_url,
        '#attributes' => [
          'class' => ['button', 'button--primary', 'claim-entity-btn'],
          'style' => 'background:#16a34a; color:#fff; padding:8px 18px; border-radius:6px; text-decoration:none; font-weight:bold;',
        ],
      ];
    }
    else {
      $inner['text'] = [
        '#type' => 'html_tag',
        '#tag' => 'em',
        '#value' => (string) ($info['reason'] ?? ''),
        '#attributes' => ['style' => 'color:#64748b;'],
      ];
    }

    $build['content'] = $inner;
    return $build;
  }

}
