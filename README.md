# Claim Access Rights

A comprehensive, enterprise-grade access control and reservation system for Drupal 10, 11, and 12.

**Claim Access Rights** allows authenticated users to claim granular permissions (`view`, `edit`) on any content entity (such as Listings, Places, Custom Blocks, Media assets, or Taxonomy Terms). It features configurable conflict resolution modes (`Exclusive`, `Replace`, `Append`), scheduled time-limited or indefinite access, interval overlap validation, ECA workflow automation, a customizable Views-based admin dashboard, and a personal user self-service portal.

---

## Table of Contents

- [Features](#features)
- [Compatibility](#compatibility)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [How It Works](#how-it-works)
  - [Access Modes](#access-modes)
  - [Duration & Indefinite Access](#duration--indefinite-access)
  - [Exclusive Overlap Conflict Detection](#exclusive-overlap-conflict-detection)
  - [Permanently Claimed State](#permanently-claimed-state)
- [Dashboards & Interfaces](#dashboards--interfaces)
  - [Sitewide Admin Dashboard](#sitewide-admin-dashboard)
  - [User Self-Service Portal](#user-self-service-portal)
- [Workflow & ECA Automation](#workflow--eca-automation)
- [Developer API & Architecture](#developer-api--architecture)
  - [Service: `claim_access_rights.manager`](#service-claim_access_rightsmanager)
  - [Access Hook Integration](#access-hook-integration)
  - [Database Schema](#database-schema)
- [Troubleshooting & Cron](#troubleshooting--cron)
- [License](#license)

---

## Features

- **Universal Content Entity Support**: Works out-of-the-box with any content entity type (`node`, `block_content`, `media`, `taxonomy_term`, and custom entity types).
- **Flexible Access Modes**:
  - **Exclusive Mode**: Only one user holds active access during a given duration; prevents conflicting overlapping requests.
  - **Replace Mode**: Approving a new claim automatically supersedes any previous active grants on that entity.
  - **Append Mode**: Multiple claimants can concurrently share access rights on the same entity.
- **Granular Date Ranges & Indefinite Access**:
  - **Start Date (`starts_at`)**: Schedule future bookings and access windows. Permissions remain inactive until the start date arrives.
  - **End Date (`expires_at`)**: Time-limited access with automatic revocation upon expiration.
  - **Indefinite Access (`expires_at = 0`)**: Allows granting permanent access without an expiration date.
- **Real-Time Overlap Conflict Prevention**:
  - Checks requested dates against approved exclusive durations during the submission/request phase.
  - Generates clear, friendly validation errors displaying the exact conflicting reservation window.
- **Grayed-Out State for Permanent Claims**:
  - If content is claimed indefinitely under exclusive mode, new claim requests are blocked and the claim button on entity view displays as a grayed-out, disabled button (`Claiming Unavailable`).
- **Customizable Views Sitewide Dashboard (`/admin/content/claims`)**:
  - KPI metric header cards (Active, Indefinite, Expiring Soon, Expired, Revoked/Replaced).
  - Exposed filters by Status, Entity Type, and **Expiration Type** (`Indefinite` vs `Time-Limited`).
  - Distinct styling for indefinite claims (purple badge and highlighted table rows).
  - Inline administrative operations dropbuttons (Extend, Revoke, Delete).
- **Claimant User Portal (`/user/{user}/claims` & `/my-claims`)**:
  - Dedicated personal tab for authenticated users to view active, pending, or expiring claims.
  - Built-in self-service **Request Extension** action.
  - Granular access protection ensuring users can only view their own claims.
- **Automated Workflow & ECA Integration**:
  - Pre-configured Webform submission form (`claim_listing`).
  - Custom ECA Action Plugin (`claim_access_rights_grant`) for automated or conditional approvals.
  - Packaged ECA BPMN approval model.

---

## Compatibility

- **Drupal 10.x** (10.0+)
- **Drupal 11.x** (11.0+)
- **Drupal 12.x** (Fully compatible and ready)
- **PHP**: 8.1, 8.2, 8.3, 8.4+

The module utilizes native PHP 8 attributes (`#[ViewsField]`, `#[ViewsArea]`, `#[ViewsFilter]`, `#[Action]`) alongside backward-compatible annotations, ensuring complete readiness for Drupal 12 when Doctrine annotations are deprecated and removed from Drupal core.

---

## Requirements

The module requires the following core and contributed modules:

- `drupal:node`
- `drupal:user`
- `webform:webform` & `webform:webform_ui`
- `eca:eca`, `eca:eca_content`, `eca:eca_ui` (optional for visual workflow automation)
- `bpmn_io:bpmn_io` (optional for BPMN diagram rendering)

---

## Installation

1. Place the module in your Drupal site's `modules/custom/claim_access_rights` directory.
2. Enable the module using Drush:
   ```bash
   drush en claim_access_rights -y
   ```
3. Run database updates to establish schema tables and indexes:
   ```bash
   drush updatedb -y
   ```
4. Clear Drupal cache:
   ```bash
   drush cache:rebuild
   ```

Upon installation, authenticated users are automatically granted the `claim access rights` permission.

---

## Configuration

Navigate to **Administration > Configuration > People > Claim Access Rights** (`/admin/config/people/claim-access-rights`):

1. **Claimable Content Entity Types & Bundles**:
   - Expand the entity type checkboxes (Content, Custom block, Media, Taxonomy term) and choose which bundles allow access claiming.
2. **Claim Mode**:
   - Choose between **Exclusive**, **Replace**, or **Append**.
3. **Default Access Expiry**:
   - Specify the default duration in days (e.g. `30` days, or set to `0` for indefinite access by default).
4. **Auto-Approval**:
   - Check **Auto-approve claims immediately** to bypass manual review and grant access instantly upon form submission, or leave unchecked to process approvals through ECA workflows or administrator moderation.

---

## How It Works

### Access Modes

| Mode | Behavior |
| :--- | :--- |
| **Exclusive** | Only one active user grant is permitted at any given time. Future bookings can be made for non-overlapping durations. If a grant is indefinite (`expires_at = 0`), no new claims can be made. |
| **Replace** | When a new claim is approved, any previous active grants on that entity are updated to `replaced`, giving exclusive ownership to the newest claimant. |
| **Append** | Multiple users can claim and concurrently hold view/edit rights on the same entity without interfering with each other. |

### Duration & Indefinite Access

- **`starts_at`**: An unsigned integer timestamp. When a claim request is scheduled for a future date, the user will not receive access until `starts_at <= current_time`.
- **`expires_at`**: An unsigned integer timestamp representing the end of access.
- **Indefinite Access**: If `expires_at === 0`, access never expires unless manually revoked.

### Exclusive Overlap Conflict Detection

When requesting access on an Exclusive-mode entity, the form validates the interval against existing active exclusive grants:

$$\text{Overlap occurs unless } (\text{requested\_end} \le \text{grant\_start}) \lor (\text{grant\_end} > 0 \land \text{requested\_start} \ge \text{grant\_end})$$

If an overlap is detected, the submission form displays an error:
> *"Access is already exclusively reserved for this content from [Start Date] to [End Date]. Access is not possible during this overlapping duration. Please choose dates outside this window."*

### Permanently Claimed State

When an entity is claimed under **Exclusive** mode with **Indefinite Access** (`expires_at = 0`):
- New claim requests cannot be submitted.
- The claim banner on the entity view displays:
  - Title: **Exclusive Access Granted**
  - Message: *"This content has been claimed indefinitely under exclusive access. New access requests cannot be accepted."*
  - Button: **Claiming Unavailable** (grayed out and disabled).

---

## Dashboards & Interfaces

### Sitewide Admin Dashboard

Access via **Administration > Content > Access Claims** (`/admin/content/claims`):

- **KPI Header Area**: Shows real-time counts for Active Claims, No Expiry (Indefinite), Expiring Soon (< 7 Days), Expired Claims, and Replaced/Revoked Claims.
- **Exposed Filters**:
  - Filter by **Status** (`active`, `expired`, `revoked`, `replaced`).
  - Filter by **Entity Type** (`node`, `block_content`, `media`, `taxonomy_term`).
  - Filter by **Expiration Type** (`Indefinite (No Expiration)` vs `Has Expiration Date`).
- **Visual Styling**: Indefinite claims are highlighted with a soft purple background, purple border accent, and an infinity badge `♾ NO EXPIRY (INDEFINITE)`.
- **Administrative Actions**: Extend access by 30 days, revoke active grants, or delete grant records.

### User Self-Service Portal

Access via `/my-claims` (or the **My Claims** tab on user profiles: `/user/{uid}/claims`):

- Displays all claims held by the logged-in user.
- Highlights expiring or expired claims.
- Users can click **Request Extension** to submit a prolongation request with a proposed new end date and justification notes.
- Protected by `UserClaimsAccessCheck`: regular users cannot access other users' claim tabs.

---

## Workflow & ECA Automation

The module includes an ECA Action plugin:

- **Plugin ID**: `claim_access_rights_grant`
- **Label**: `Grant claim access rights`
- **Context**: `webform_submission`

When a user submits the `claim_listing` webform, ECA triggers this action to extract the entity type, entity ID, claimant UID, requested rights (`view`, `edit`), start date, indefinite flag, and end date, automatically granting permissions.

An auto-approval ECA recipe is included at:
`config/install/eca.eca.claim_access_auto_approval.yml`

---

## Developer API & Architecture

### Service: `claim_access_rights.manager`

Inject or load the manager via:
```php
/** @var \Drupal\claim_access_rights\ClaimAccessManagerInterface $manager */
$manager = \Drupal::service('claim_access_rights.manager');
```

#### Core Methods:

```php
// Grant or update an access right.
$grant_id = $manager->grantAccess(
  entity_type: 'node',
  entity_id: 42,
  uid: 5,
  rights: ['view', 'edit'],
  mode: 'exclusive',     // null to use configured default
  expires_at: 1793471399, // 0 for indefinite
  notes: 'Approved reservation',
  submission_id: null,
  starts_at: 1790586739  // null for immediate creation time
);

// Check if an account currently holds access for an operation ('view', 'update', 'delete').
$has_access = $manager->hasAccess($entity, $account, 'update');

// Check if an entity is available to be claimed.
$claim_info = $manager->isClaimable($entity, $account);
// Returns: ['claimable' => bool, 'permanently_claimed' => bool, 'reason' => string, 'mode' => string, ...]

// Check for conflicting overlapping exclusive reservations.
$conflict = $manager->getOverlappingExclusiveGrant('node', 42, $starts_at, $expires_at);

// Extend an existing grant by $days (default 30).
$manager->extendGrant($grant_id, 30);

// Revoke an active grant.
$manager->revokeGrant($grant_id);

// Retrieve statistics for dashboards.
$stats = $manager->getStatistics();
```

### Access Hook Integration

Universal access enforcement is executed via `hook_entity_access()` in `claim_access_rights.module`:

```php
function claim_access_rights_entity_access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
  $manager = \Drupal::service('claim_access_rights.manager');
  if ($manager->hasAccess($entity, $account, $operation)) {
    return AccessResult::allowed()->cachePerUser()->addCacheableDependency($entity);
  }
  return AccessResult::neutral()->cachePerUser()->addCacheableDependency($entity);
}
```

### Database Schema

Grants are stored in the `{claim_access_grants}` table:

| Column | Type | Description |
| :--- | :--- | :--- |
| `id` | `serial` | Primary Key: Unique grant ID. |
| `entity_type` | `varchar(32)` | Entity type ID (`node`, `block_content`, `media`, etc.). |
| `entity_id` | `int unsigned` | Target entity numeric ID. |
| `uid` | `int unsigned` | Claimant user ID. |
| `rights` | `varchar(255)` | Comma-separated rights (`view,edit`). |
| `mode` | `varchar(32)` | Claim mode: `exclusive`, `replace`, `append`. |
| `status` | `varchar(32)` | Status: `active`, `revoked`, `expired`, `replaced`. |
| `created` | `int unsigned` | Timestamp when created. |
| `starts_at` | `int unsigned` | Timestamp when grant takes effect (`0` = immediately). |
| `expires_at` | `int unsigned` | Timestamp when grant expires (`0` = indefinite). |
| `submission_id` | `int unsigned` | Optional webform submission ID. |
| `notes` | `text` | Notes or verification comments. |

**Indexes**:
- `entity_target`: `['entity_type', 'entity_id', 'status']`
- `user_grants`: `['uid', 'status']`
- `status_expiry`: `['status', 'expires_at']`
- `date_range`: `['entity_type', 'entity_id', 'status', 'starts_at', 'expires_at']`

---

## Troubleshooting & Cron

- **Expired Grants**: The module hooks into `hook_cron()` to automatically update active grants whose `expires_at > 0 && expires_at <= current_time` to `expired` status and invalidate cache tags.
- **Cache Tags**: Grants automatically invalidate the entity cache tag (e.g. `node:42`), the claimant user tag (`user:5`), and the global list tag `claim_access_grants_list` whenever status changes.
- **Manual Purge**: You can trigger cron manually via Drush:
  ```bash
  drush cron
  ```

---

## License

This project is licensed under the GNU General Public License, version 2 or later (GPL-2.0-or-later).
