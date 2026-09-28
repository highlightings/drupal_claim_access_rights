<?php

/**
 * @file
 * Provide views data for claim_access_rights.
 */

declare(strict_types=1);

/**
 * Implements hook_views_data().
 */
function claim_access_rights_views_data(): array {
  $data = [];

  $data['claim_access_grants']['table']['group'] = t('Claim Access Rights');

  $data['claim_access_grants']['table']['base'] = [
    'field' => 'id',
    'title' => t('Claim Access Grant'),
    'help' => t('Stores claimed and granted access permissions across entities.'),
    'weight' => -10,
  ];

  // Grant ID.
  $data['claim_access_grants']['id'] = [
    'title' => t('Grant ID'),
    'help' => t('The unique ID of the access grant.'),
    'field' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
  ];

  // Entity Type.
  $data['claim_access_grants']['entity_type'] = [
    'title' => t('Entity Type'),
    'help' => t('The type of the target entity (node, block_content, media, taxonomy_term, etc.).'),
    'field' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  // Entity ID.
  $data['claim_access_grants']['entity_id'] = [
    'title' => t('Entity ID'),
    'help' => t('The numeric ID of the target entity.'),
    'field' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
  ];

  // Target Entity Display (Type Badge & Link).
  $data['claim_access_grants']['entity_target'] = [
    'title' => t('Target Entity'),
    'help' => t('Displays the target entity type badge and linked title.'),
    'field' => [
      'id' => 'claim_entity_field',
    ],
  ];

  // Claimant User ID & Relationship to User Entity.
  $data['claim_access_grants']['uid'] = [
    'title' => t('Claimant User'),
    'help' => t('The user who requested and was granted access.'),
    'field' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
    'relationship' => [
      'base' => 'users_field_data',
      'base field' => 'uid',
      'id' => 'standard',
      'label' => t('Claimant user'),
    ],
  ];

  // Rights.
  $data['claim_access_grants']['rights'] = [
    'title' => t('Rights Granted'),
    'help' => t('The claimed rights (e.g. view, edit).'),
    'field' => [
      'id' => 'claim_rights_badge',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];

  // Access Mode.
  $data['claim_access_grants']['mode'] = [
    'title' => t('Access Mode'),
    'help' => t('The claim mode (exclusive, replace, or append).'),
    'field' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];

  // Status.
  $data['claim_access_grants']['status'] = [
    'title' => t('Status'),
    'help' => t('The status of the access grant (active, expired, revoked, replaced).'),
    'field' => [
      'id' => 'claim_status_badge',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];

  // Starts At Date.
  $data['claim_access_grants']['starts_at'] = [
    'title' => t('Start Date'),
    'help' => t('Timestamp when the access grant becomes effective.'),
    'field' => [
      'id' => 'date',
    ],
    'filter' => [
      'id' => 'date',
    ],
    'sort' => [
      'id' => 'date',
    ],
  ];

  // Expiration Date.
  $data['claim_access_grants']['expires_at'] = [
    'title' => t('Expiration Date'),
    'help' => t('Timestamp when the access grant expires.'),
    'field' => [
      'id' => 'claim_expiry_badge',
    ],
    'filter' => [
      'id' => 'date',
    ],
    'sort' => [
      'id' => 'date',
    ],
  ];

  // Expiration Type Filter (Indefinite vs Expiring).
  $data['claim_access_grants']['expiry_type'] = [
    'title' => t('Expiration Type'),
    'help' => t('Filter by indefinite (no expiration) access or expiring access.'),
    'filter' => [
      'id' => 'claim_expiry_type',
      'real field' => 'expires_at',
    ],
  ];

  // Created Date.
  $data['claim_access_grants']['created'] = [
    'title' => t('Granted Date'),
    'help' => t('Timestamp when the access grant was created.'),
    'field' => [
      'id' => 'date',
    ],
    'filter' => [
      'id' => 'date',
    ],
    'sort' => [
      'id' => 'date',
    ],
  ];

  // Notes.
  $data['claim_access_grants']['notes'] = [
    'title' => t('Notes'),
    'help' => t('Optional notes provided during claiming.'),
    'field' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
  ];

  // Operations.
  $data['claim_access_grants']['operations'] = [
    'title' => t('Operations'),
    'help' => t('Action buttons to revoke, extend, or delete the grant.'),
    'field' => [
      'id' => 'claim_operations_field',
    ],
  ];

  // Area: KPI Stats Header.
  $data['claim_access_grants']['claim_stats_area'] = [
    'title' => t('Claim KPI Statistics'),
    'help' => t('Displays KPI metric cards for active, expiring, expired, and revoked claims.'),
    'area' => [
      'id' => 'claim_stats_area',
    ],
  ];

  return $data;
}
