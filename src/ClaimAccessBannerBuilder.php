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
      ],
      '#attached' => [
        'library' => ['claim_access_rights/banner'],
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
        'class' => ['claim-access-entity-box__inner'],
      ],
    ];

    if (!empty($info['user_is_claimant'])) {
      $grant = $info['grant'];
      $expires = (int) $grant['expires_at'] > 0
        ? $this->dateFormatter->format((int) $grant['expires_at'], 'short')
        : (string) $this->t('Never');

      $inner['text'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['claim-access-banner__text']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $this->t('✓ You manage this @type', ['@type' => $type_label]),
          '#attributes' => ['class' => ['claim-access-banner__title', 'claim-access-banner__title--managed']],
        ],
        'details' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Granted Rights: @rights | Expires: @expires', [
            '@rights' => strtoupper((string) $grant['rights']),
            '@expires' => $expires,
          ]),
          '#attributes' => ['class' => ['claim-access-banner__details']],
        ],
      ];

      if (in_array('edit', explode(',', (string) $grant['rights']), TRUE) && $entity->hasLinkTemplate('edit-form')) {
        $inner['action'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['claim-access-banner__action']],
          'link' => [
            '#type' => 'link',
            '#title' => $this->t('Edit @type', ['@type' => $type_label]),
            '#url' => $entity->toUrl('edit-form'),
            '#attributes' => [
              'class' => ['button', 'button--primary', 'claim-access-banner__btn', 'claim-access-banner__btn--edit'],
            ],
          ],
        ];
      }
    }
    elseif (!empty($info['permanently_claimed'])) {
      $inner['text'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['claim-access-banner__text']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $this->t('Exclusive Access Granted'),
          '#attributes' => ['class' => ['claim-access-banner__title', 'claim-access-banner__title--exclusive']],
        ],
        'details' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('This @type has been claimed indefinitely under exclusive access. New access requests cannot be accepted.', [
            '@type' => strtolower($type_label),
          ]),
          '#attributes' => ['class' => ['claim-access-banner__details']],
        ],
      ];
      $inner['action'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['claim-access-banner__action']],
        'button' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => (string) $this->t('Claiming Unavailable'),
          '#attributes' => [
            'type' => 'button',
            'disabled' => 'disabled',
            'class' => ['button', 'button--disabled', 'claim-access-banner__btn', 'claim-access-banner__btn--disabled'],
            'title' => (string) $this->t('Claiming is not possible for content with indefinite exclusive access.'),
          ],
        ],
      ];
    }
    elseif ($this->currentUser->isAnonymous()) {
      $destination = $entity->hasLinkTemplate('canonical') ? $entity->toUrl()->toString() : '';
      $login_url = Url::fromRoute('user.login', [], ['query' => $destination ? ['destination' => $destination] : []]);

      $inner['text'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['claim-access-banner__text']],
        'prompt' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $this->t('Want to manage this @type?', ['@type' => strtolower($type_label)]),
          '#suffix' => ' ',
        ],
        'login' => [
          '#type' => 'link',
          '#title' => $this->t('Log in to claim access'),
          '#url' => $login_url,
          '#attributes' => ['class' => ['claim-access-banner__login-link']],
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
        '#attributes' => ['class' => ['claim-access-banner__text']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $this->t('Is this your @type?', ['@type' => strtolower($type_label)]),
          '#attributes' => ['class' => ['claim-access-banner__title', 'claim-access-banner__title--claimable']],
        ],
        'details' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $notice_text,
          '#attributes' => ['class' => ['claim-access-banner__details']],
        ],
      ];
      $inner['action'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['claim-access-banner__action']],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Claim This @type', ['@type' => $type_label]),
          '#url' => $claim_url,
          '#attributes' => [
            'class' => ['button', 'button--primary', 'claim-entity-btn', 'claim-access-banner__btn', 'claim-access-banner__btn--claim'],
          ],
        ],
      ];
    }
    else {
      $inner['text'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => (string) ($info['reason'] ?? ''),
        '#attributes' => ['class' => ['claim-access-banner__reason']],
      ];
    }

    $build['content'] = $inner;
    return $build;
  }

}
