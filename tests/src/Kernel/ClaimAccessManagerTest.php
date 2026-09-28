<?php

declare(strict_types=1);

namespace Drupal\Tests\claim_access_rights\Kernel;

use Drupal\claim_access_rights\ClaimAccessManager;
use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Covers the grant lifecycle rules in the claim access manager.
 *
 * @group claim_access_rights
 */
final class ClaimAccessManagerTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'webform', 'webform_ui',
    'eca', 'eca_content', 'eca_ui', 'bpmn_io', 'claim_access_rights',
  ];

  private ClaimAccessManagerInterface $manager;
  private Node $node;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('claim_access_rights', ['claim_access_grants']);
    $this->installConfig(['claim_access_rights']);
    NodeType::create(['type' => 'listing', 'name' => 'Listing'])->save();
    $this->node = Node::create(['type' => 'listing', 'title' => 'Hall']);
    $this->node->save();
    $this->config('claim_access_rights.settings')
      ->set('enabled_entity_types', ['node' => ['listing']])
      ->set('claim_mode', 'exclusive')
      ->set('allow_permanent_claims', FALSE)
      ->set('max_claim_days', 90)
      ->save();
    $this->manager = $this->container->get('claim_access_rights.manager');
  }

  public function testRevokedGrantCannotBeSelfReinstated(): void {
    $user = $this->createUser(['claim access rights']);
    $id = $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user->id(), ['view', 'edit'], NULL, time() + 86400);
    $this->manager->revokeGrant($id);
    $this->assertFalse($this->manager->requestExtension($id, 30, 'please'));
    $this->assertSame('revoked', $this->manager->getGrant($id)['status']);
    // An explicit administrator reinstatement still works.
    $this->assertTrue($this->manager->extendGrant($id, 30, TRUE));
  }

  public function testExclusiveOverlapIsRejectedAndOwnGrantDoesNotMaskIt(): void {
    $a = $this->createUser();
    $b = $this->createUser();
    $now = time();
    $this->manager->grantAccess('node', (int) $this->node->id(), (int) $a->id(), ['view'], NULL, $now + 10 * 86400, NULL, NULL, $now + 5 * 86400);
    $this->expectException(\RuntimeException::class);
    $this->manager->grantAccess('node', (int) $this->node->id(), (int) $b->id(), ['view'], NULL, $now + 8 * 86400, NULL, NULL, $now + 6 * 86400);
  }

  public function testInvalidInputIsRejected(): void {
    $user = $this->createUser();
    $this->expectException(\InvalidArgumentException::class);
    $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user->id(), ['delete']);
  }

  public function testWindowLimits(): void {
    $now = time();
    $this->assertNotNull($this->manager->validateClaimWindow($now, 0), 'Permanent claims are refused by default.');
    $this->assertNotNull($this->manager->validateClaimWindow($now, $now + 200 * 86400), 'Windows beyond max_claim_days are refused.');
    $this->assertNull($this->manager->validateClaimWindow($now, $now + 30 * 86400));
  }

  /**
   * grantAccess() enforces claim-window policy independently of its caller.
   */
  public function testGrantAccessEnforcesWindowPolicy(): void {
    $user = $this->createUser();
    $now = time();

    // Permanent grants are disabled by the test configuration.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Permanent access cannot be requested');

    $this->manager->grantAccess(
      'node',
      (int) $this->node->id(),
      (int) $user->id(),
      ['view'],
      'exclusive',
      0,
      NULL,
      NULL,
      $now
    );
  }

  /**
   * grantAccess() enforces the configured active-claim limit.
   */
  public function testGrantAccessEnforcesUserClaimLimit(): void {
    $this->config('claim_access_rights.settings')
      ->set('max_active_claims_per_user', 1)
      ->save();

    $user = $this->createUser();
    $now = time();

    $this->manager->grantAccess(
      'node',
      (int) $this->node->id(),
      (int) $user->id(),
      ['view'],
      'exclusive',
      $now + 86400,
      NULL,
      NULL,
      $now
    );

    $node2 = Node::create([
      'type' => 'listing',
      'title' => 'Limit Node',
    ]);
    $node2->save();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('maximum number of active claims (1)');

    $this->manager->grantAccess(
      'node',
      (int) $node2->id(),
      (int) $user->id(),
      ['view'],
      'exclusive',
      $now + 86400,
      NULL,
      NULL,
      $now
    );
  }

  public function testExpiredGrantDeniesAccessWithoutWriting(): void {
    $user = $this->createUser();
    $id = $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user->id(), ['view', 'edit'], NULL, time() + 1);
    $this->container->get('database')->update('claim_access_grants')->fields(['expires_at' => time() - 10])->condition('id', $id)->execute();
    $this->assertFalse($this->manager->hasAccess($this->node, $user, 'update'));
    // hasAccess() is read-only: the status is only changed by cron.
    $this->assertSame('active', $this->manager->getGrant($id)['status']);
    $this->assertSame(1, $this->manager->purgeExpiredGrants());
    $this->assertSame('expired', $this->manager->getGrant($id)['status']);
  }

  public function testEntityDeletionCleansUpGrants(): void {
    $user = $this->createUser(['claim access rights']);
    $id = $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user->id(), ['view', 'edit'], NULL, time() + 86400);
    $this->assertNotEmpty($this->manager->getGrant($id));

    $this->node->delete();
    $this->assertNull($this->manager->getGrant($id));
  }

  public function testUserDeletionCleansUpGrants(): void {
    $user = $this->createUser(['claim access rights']);
    $node = Node::create(['type' => 'listing', 'title' => 'Hall 2']);
    $node->save();
    $id = $this->manager->grantAccess('node', (int) $node->id(), (int) $user->id(), ['view', 'edit'], NULL, time() + 86400);
    $this->assertNotEmpty($this->manager->getGrant($id));

    $user->delete();
    $this->assertNull($this->manager->getGrant($id));
  }

  public function testOpenEndedIntervalsAndAdjacentWindows(): void {
    $this->config('claim_access_rights.settings')
      ->set('allow_permanent_claims', TRUE)
      ->save();

    $u1 = $this->createUser();
    $u2 = $this->createUser();
    $u3 = $this->createUser();
    $now = time();

    // 1. Adjacent windows: [now, now + 1000] and [now + 1000, now + 2000] do not conflict.
    $g1 = $this->manager->grantAccess('node', (int) $this->node->id(), (int) $u1->id(), ['view'], 'exclusive', $now + 1000, NULL, NULL, $now);
    $this->assertGreaterThan(0, $g1);

    $g2 = $this->manager->grantAccess('node', (int) $this->node->id(), (int) $u2->id(), ['view'], 'exclusive', $now + 2000, NULL, NULL, $now + 1000);
    $this->assertGreaterThan(0, $g2);

    // 2. Open-ended request conflicts with overlapping finite window.
    $this->expectException(\RuntimeException::class);
    $this->manager->grantAccess('node', (int) $this->node->id(), (int) $u3->id(), ['view'], 'exclusive', 0, NULL, NULL, $now + 500);
  }

  public function testRequestExtensionPreservesActiveStatusAndPendingRenewal(): void {
    $this->config('claim_access_rights.settings')
      ->set('user_extension_auto_approve', FALSE)
      ->save();

    $user = $this->createUser(['claim access rights']);
    $id = $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user->id(), ['view', 'edit'], NULL, time() + 86400);

    // 1. Request extension on an ACTIVE grant.
    $success = $this->manager->requestExtension($id, 30, 'Need more time');
    $this->assertTrue($success);

    // An active grant remains STATUS_ACTIVE so access is preserved and queries are not corrupted.
    $grant = $this->manager->getGrant($id);
    $this->assertSame(ClaimAccessManagerInterface::STATUS_ACTIVE, $grant['status']);
    $this->assertSame(1, (int) $grant['extension_requested']);
    $this->assertTrue(ClaimAccessManager::isExtensionPending($grant));
    $this->assertTrue($this->manager->hasAccess($this->node, $user, 'update'));

    // 2. Request extension on an EXPIRED grant: status transitions to STATUS_PENDING.
    $user2 = $this->createUser(['claim access rights']);
    $node2 = Node::create(['type' => 'listing', 'title' => 'Hall Expired']);
    $node2->save();
    $id2 = $this->manager->grantAccess('node', (int) $node2->id(), (int) $user2->id(), ['view', 'edit'], NULL, time() + 1);
    $this->container->get('database')->update('claim_access_grants')
      ->fields(['expires_at' => time() - 100, 'status' => ClaimAccessManagerInterface::STATUS_EXPIRED])
      ->condition('id', $id2)
      ->execute();

    $this->assertTrue($this->manager->requestExtension($id2, 30, 'Renew expired'));
    $grant2 = $this->manager->getGrant($id2);
    $this->assertSame(ClaimAccessManagerInterface::STATUS_PENDING, $grant2['status']);
    $this->assertSame(1, (int) $grant2['extension_requested']);
    $this->assertTrue(ClaimAccessManager::isExtensionPending($grant2));
    $this->assertFalse($this->manager->hasAccess($node2, $user2, 'update'));

    // Pending expired renewal does NOT block other users from claiming exclusive access.
    $user3 = $this->createUser();
    $id3 = $this->manager->grantAccess('node', (int) $node2->id(), (int) $user3->id(), ['view'], 'exclusive', time() + 1000);
    $this->assertGreaterThan(0, $id3);

    // Admin approves/extends the active grant: approval note is recorded and flag cleared.
    $this->assertTrue($this->manager->extendGrant($id, 30, TRUE));
    $updated_grant = $this->manager->getGrant($id);
    $this->assertSame(ClaimAccessManagerInterface::STATUS_ACTIVE, $updated_grant['status']);
    $this->assertSame(0, (int) $updated_grant['extension_requested']);
    $this->assertFalse(ClaimAccessManager::isExtensionPending($updated_grant));
    $this->assertTrue($this->manager->hasAccess($this->node, $user, 'update'));
  }

  public function testExclusiveConflictAndModeIndependence(): void {
    $u1 = $this->createUser();
    $u2 = $this->createUser();
    $u3 = $this->createUser();
    $now = time();

    // 1. Grant access under exclusive mode.
    $this->manager->grantAccess('node', (int) $this->node->id(), (int) $u1->id(), ['view'], 'exclusive', $now + 5000, NULL, NULL, $now);

    // Another user requesting exclusive access during an overlapping window is blocked.
    try {
      $this->manager->grantAccess('node', (int) $this->node->id(), (int) $u2->id(), ['view'], 'exclusive', $now + 3000, NULL, NULL, $now + 1000);
      $this->fail('Expected overlapping exclusive grant to be blocked.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Exclusive access is already reserved', $e->getMessage());
    }

    // 2. An append-mode grant on another node does not block exclusive requests.
    $node2 = Node::create(['type' => 'listing', 'title' => 'Append Node']);
    $node2->save();
    $this->manager->grantAccess('node', (int) $node2->id(), (int) $u1->id(), ['view'], 'append', $now + 5000, NULL, NULL, $now);
    $exclusive_id = $this->manager->grantAccess('node', (int) $node2->id(), (int) $u3->id(), ['view'], 'exclusive', $now + 3000, NULL, NULL, $now + 1000);
    $this->assertGreaterThan(0, $exclusive_id);
  }

  public function testAllowedRightsFailClosed(): void {
    $user = $this->createUser(['claim access rights']);
    $config = $this->container->get('config.factory')->getEditable('claim_access_rights.settings');

    // Misconfigured to unknown right.
    $config->set('allowed_rights', ['delete'])->save();
    $info = $this->manager->isClaimable($this->node, $user);
    $this->assertFalse($info['claimable']);

    try {
      $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user->id(), ['view']);
      $this->fail('Expected grantAccess to fail when allowed_rights fails closed.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('No access rights are currently claimable', $e->getMessage());
    }

    // Restore valid rights.
    $config->set('allowed_rights', ['view', 'edit'])->save();
    $info_restored = $this->manager->isClaimable($this->node, $user);
    $this->assertTrue($info_restored['claimable']);
  }

  public function testExtensionNoteSpoofResistance(): void {
    $user = $this->createUser(['claim access rights']);
    $id = $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user->id(), ['view'], 'exclusive', time() + 86400);

    // Malicious user attempts to fake an approval in their reason string.
    $malicious_reason = "Reason: Extension approved: please approve\nAnother line";
    $this->assertTrue($this->manager->requestExtension($id, 30, $malicious_reason));

    $grant = $this->manager->getGrant($id);
    // Request must still be recognized as pending on the record flag.
    $this->assertSame(1, (int) $grant['extension_requested']);
    $this->assertTrue(ClaimAccessManager::isExtensionPending($grant));

    // Cannot spam second extension while one is already pending.
    $this->assertFalse($this->manager->requestExtension($id, 30, 'Spam request'));

    // Admin approval clears the pending state and column flag.
    $this->assertTrue($this->manager->extendGrant($id, 30, TRUE));
    $updated_grant = $this->manager->getGrant($id);
    $this->assertSame(0, (int) $updated_grant['extension_requested']);
    $this->assertFalse(ClaimAccessManager::isExtensionPending($updated_grant));
  }

  public function testGrantsMaxAgeCapping(): void {
    $now = time();
    // Grant expiring in 10 days (864000 seconds).
    $grants = [
      ['starts_at' => $now, 'expires_at' => $now + 864000],
    ];
    $max_age = $this->manager->getGrantsMaxAge($grants);
    $this->assertSame(3600, $max_age);

    // Grant expiring in 300 seconds.
    $short_grants = [
      ['starts_at' => $now, 'expires_at' => $now + 300],
    ];
    $short_max_age = $this->manager->getGrantsMaxAge($short_grants);
    $this->assertLessThanOrEqual(300, $short_max_age);
  }

  public function testHasAccessOperationsAndFutureWindow(): void {
    $now = time();
    $user_view_only = $this->createUser();
    $user_full = $this->createUser();
    $user_future = $this->createUser();

    // 1. Grant 'view' only.
    $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user_view_only->id(), ['view'], NULL, $now + 86400, NULL, NULL, $now);
    $this->assertTrue($this->manager->hasAccess($this->node, $user_view_only, 'view'));
    $this->assertFalse($this->manager->hasAccess($this->node, $user_view_only, 'update'));

    // 2. Grant 'view' and 'edit'.
    $node2 = Node::create(['type' => 'listing', 'title' => 'Hall Edit Test']);
    $node2->save();
    $this->manager->grantAccess('node', (int) $node2->id(), (int) $user_full->id(), ['view', 'edit'], NULL, $now + 86400, NULL, NULL, $now);
    $this->assertTrue($this->manager->hasAccess($node2, $user_full, 'view'));
    $this->assertTrue($this->manager->hasAccess($node2, $user_full, 'update'));

    // 3. Grant with starts_at in the future: access must be FALSE until start time arrives.
    $node3 = Node::create(['type' => 'listing', 'title' => 'Hall Future Test']);
    $node3->save();
    $this->manager->grantAccess('node', (int) $node3->id(), (int) $user_future->id(), ['view', 'edit'], NULL, $now + 86400, NULL, NULL, $now + 3600);
    $this->assertFalse($this->manager->hasAccess($node3, $user_future, 'view'));
    $this->assertFalse($this->manager->hasAccess($node3, $user_future, 'update'));

    // 4. Anonymous user is always denied access.
    $anonymous = \Drupal::entityTypeManager()->getStorage('user')->load(0);
    if ($anonymous) {
      $this->assertFalse($this->manager->hasAccess($this->node, $anonymous, 'view'));
    }

    // 5. Disabled entity bundle is always denied access.
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $page = Node::create(['type' => 'page', 'title' => 'Disabled Page']);
    $page->save();
    $this->assertFalse($this->manager->hasAccess($page, $user_full, 'view'));
  }

  public function testIsClaimableStatesAndReasons(): void {
    $now = time();
    $user1 = $this->createUser();
    $user2 = $this->createUser();

    // 1. Fresh, enabled entity is claimable.
    $info1 = $this->manager->isClaimable($this->node, $user1);
    $this->assertTrue($info1['claimable']);
    $this->assertSame('This item is available to claim.', $info1['reason']);

    // 2. Once claimed, the claimant viewing it sees they already hold an active grant.
    $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user1->id(), ['view'], 'exclusive', $now + 86400, NULL, NULL, $now);
    $info_claimant = $this->manager->isClaimable($this->node, $user1);
    $this->assertFalse($info_claimant['claimable']);
    $this->assertTrue($info_claimant['user_is_claimant']);

    // 3. Another user viewing an exclusive entity with a finite active window sees windows notice.
    $info_other = $this->manager->isClaimable($this->node, $user2);
    $this->assertTrue($info_other['claimable']);
    $this->assertTrue($info_other['has_exclusive_windows']);

    // 4. Another user viewing an entity with an indefinite exclusive grant (expires_at = 0).
    $this->config('claim_access_rights.settings')->set('allow_permanent_claims', TRUE)->save();
    $node_perm = Node::create(['type' => 'listing', 'title' => 'Permanent Node']);
    $node_perm->save();
    $this->manager->grantAccess('node', (int) $node_perm->id(), (int) $user1->id(), ['view'], 'exclusive', 0, NULL, NULL, $now);
    $info_perm = $this->manager->isClaimable($node_perm, $user2);
    $this->assertFalse($info_perm['claimable']);
    $this->assertTrue($info_perm['permanently_claimed']);

    // 5. Disabled bundle is not claimable.
    if (!NodeType::load('page')) {
      NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    }
    $page = Node::create(['type' => 'page', 'title' => 'Disabled Page 2']);
    $page->save();
    $info_disabled = $this->manager->isClaimable($page, $user1);
    $this->assertFalse($info_disabled['claimable']);
    $this->assertStringContainsString('not enabled', $info_disabled['reason']);
  }

  public function testReplaceModeTransitionsPriorGrants(): void {
    $now = time();
    $u1 = $this->createUser();
    $u2 = $this->createUser();

    // 1. User 1 acquires an initial grant.
    $g1 = $this->manager->grantAccess('node', (int) $this->node->id(), (int) $u1->id(), ['view', 'edit'], 'append', $now + 86400, NULL, NULL, $now);
    $this->assertSame(ClaimAccessManagerInterface::STATUS_ACTIVE, $this->manager->getGrant($g1)['status']);
    $this->assertTrue($this->manager->hasAccess($this->node, $u1, 'update'));

    // 2. User 2 claims under replace mode.
    $g2 = $this->manager->grantAccess('node', (int) $this->node->id(), (int) $u2->id(), ['view', 'edit'], 'replace', $now + 86400, NULL, NULL, $now);
    $this->assertSame(ClaimAccessManagerInterface::STATUS_ACTIVE, $this->manager->getGrant($g2)['status']);
    $this->assertSame(ClaimAccessManagerInterface::STATUS_REPLACED, $this->manager->getGrant($g1)['status']);

    // User 1 access revoked; User 2 access granted.
    $this->assertFalse($this->manager->hasAccess($this->node, $u1, 'update'));
    $this->assertTrue($this->manager->hasAccess($this->node, $u2, 'update'));

    // 3. User 1 cannot self-extend a replaced grant while another active grant exists.
    $this->assertFalse($this->manager->extendGrant($g1, 30));
  }

  public function testReclaimOwnActiveGrantRefreshesInPlace(): void {
    $now = time();
    $user = $this->createUser();

    // First claim returns ID.
    $id1 = $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user->id(), ['view'], 'exclusive', $now + 1000, NULL, NULL, $now);
    $grant1 = $this->manager->getGrant($id1);
    $this->assertSame($now + 1000, (int) $grant1['expires_at']);

    // Re-claiming the same entity by the same user updates in place without collision.
    $id2 = $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user->id(), ['view', 'edit'], 'exclusive', $now + 5000, 'Updated notes', NULL, $now);
    $this->assertSame($id1, $id2);

    $grant2 = $this->manager->getGrant($id1);
    $this->assertSame($now + 5000, (int) $grant2['expires_at']);
    $this->assertSame('view,edit', $grant2['rights']);
    $this->assertSame('Updated notes', $grant2['notes']);
  }

  public function testUserClaimLimitEnforcement(): void {
    $this->config('claim_access_rights.settings')->set('max_active_claims_per_user', 2)->save();
    $user = $this->createUser();

    $node1 = $this->node;
    $node2 = Node::create(['type' => 'listing', 'title' => 'Limit Node 2']);
    $node2->save();
    $node3 = Node::create(['type' => 'listing', 'title' => 'Limit Node 3']);
    $node3->save();

    // User claims 1st node.
    $this->assertNull($this->manager->validateUserClaimLimit((int) $user->id()));
    $this->manager->grantAccess('node', (int) $node1->id(), (int) $user->id(), ['view']);

    // User claims 2nd node.
    $this->assertNull($this->manager->validateUserClaimLimit((int) $user->id()));
    $this->manager->grantAccess('node', (int) $node2->id(), (int) $user->id(), ['view']);

    // Attempting 3rd claim exceeds limit.
    $limit_err = $this->manager->validateUserClaimLimit((int) $user->id());
    $this->assertNotNull($limit_err);
    $this->assertStringContainsString('maximum number of active claims (2)', $limit_err);
  }

  public function testStatisticsAggregation(): void {
    $now = time();
    $u1 = $this->createUser();
    $u2 = $this->createUser();

    $n1 = $this->node;
    $n2 = Node::create(['type' => 'listing', 'title' => 'Stats 2']);
    $n2->save();
    $n3 = Node::create(['type' => 'listing', 'title' => 'Stats 3']);
    $n3->save();

    // 1. Active grant.
    $this->manager->grantAccess('node', (int) $n1->id(), (int) $u1->id(), ['view'], 'exclusive', $now + 86400, NULL, NULL, $now);

    // 2. Replaced grant (via replace mode on n1).
    $this->manager->grantAccess('node', (int) $n1->id(), (int) $u2->id(), ['view'], 'replace', $now + 86400, NULL, NULL, $now);

    // 3. Revoked grant on n2.
    $g3 = $this->manager->grantAccess('node', (int) $n2->id(), (int) $u1->id(), ['view'], 'exclusive', $now + 86400, NULL, NULL, $now);
    $this->manager->revokeGrant($g3);

    // 4. Expired grant on n3.
    $g4 = $this->manager->grantAccess('node', (int) $n3->id(), (int) $u1->id(), ['view'], 'exclusive', $now + 1, NULL, NULL, $now);
    $this->container->get('database')->update('claim_access_grants')
      ->fields(['expires_at' => $now - 100, 'status' => ClaimAccessManagerInterface::STATUS_EXPIRED])
      ->condition('id', $g4)
      ->execute();

    $stats = $this->manager->getStatistics();
    $this->assertGreaterThanOrEqual(4, $stats['total']);
    $this->assertGreaterThanOrEqual(1, $stats['active']);
    $this->assertGreaterThanOrEqual(1, $stats['replaced']);
    $this->assertGreaterThanOrEqual(1, $stats['revoked']);
    $this->assertGreaterThanOrEqual(1, $stats['expired']);
    $this->assertArrayHasKey('node', $stats['by_entity_type']);
  }

  public function testExtensionAutoApproveWithLifetimeCap(): void {
    $this->config('claim_access_rights.settings')
      ->set('user_extension_auto_approve', TRUE)
      ->set('max_claim_days', 60)
      ->save();

    $now = time();
    $user = $this->createUser();
    $id = $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user->id(), ['view', 'edit'], 'exclusive', $now + 30 * 86400, NULL, NULL, $now);

    // 1. Auto-approve 15 days: total becomes 45 days (within 60 day cap).
    $this->assertTrue($this->manager->requestExtension($id, 15, 'Legitimate auto extension'));
    $grant1 = $this->manager->getGrant($id);
    $this->assertSame($now + 45 * 86400, (int) $grant1['expires_at']);
    $this->assertStringContainsString('Auto-approved extension: +15 days', $grant1['notes']);

    // 2. Requesting 30 more days would push total to 75 days (exceeds 60 days): rejected.
    $this->assertFalse($this->manager->requestExtension($id, 30, 'Exceeding cap'));
    $grant2 = $this->manager->getGrant($id);
    $this->assertSame($now + 45 * 86400, (int) $grant2['expires_at']);
  }

  public function testTargetEntityValidation(): void {
    $user = $this->createUser();
    $now = time();

    // 1. Non-existent entity ID.
    try {
      $this->manager->grantAccess('node', 999999, (int) $user->id(), ['view']);
      $this->fail('Expected exception for nonexistent entity ID.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('The target entity cannot be claimed', $e->getMessage());
    }

    // 2. Non-existent entity type.
    try {
      $this->manager->grantAccess('nonexistent_entity_type', 1, (int) $user->id(), ['view']);
      $this->fail('Expected exception for nonexistent entity type.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('The target entity cannot be claimed', $e->getMessage());
    }

    // 3. End before start timestamp.
    try {
      $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user->id(), ['view'], NULL, $now, NULL, NULL, $now + 500);
      $this->fail('Expected exception for end before start.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('The end of the access window is before its start', $e->getMessage());
    }
  }

  public function testBannerBuilderRendersStructuredArrays(): void {
    $banner_builder = $this->container->get('claim_access_rights.banner_builder');
    $user = $this->createUser();

    // 1. Banner for enabled entity with anonymous user.
    $banner_anon = $banner_builder->buildBanner('node', (int) $this->node->id());
    $this->assertNotEmpty($banner_anon);
    $this->assertSame('container', $banner_anon['#type']);
    $this->assertArrayHasKey('#cache', $banner_anon);
    $this->assertArrayHasKey('#attached', $banner_anon);
    $this->assertContains('claim_access_rights/banner', $banner_anon['#attached']['library']);
    $this->assertArrayNotHasKey('#markup', $banner_anon['content']['text']['prompt']);
    $this->assertSame('strong', $banner_anon['content']['text']['prompt']['#tag']);

    // 2. Grant access so current user is claimant.
    $this->manager->grantAccess('node', (int) $this->node->id(), (int) $user->id(), ['view', 'edit']);
    // Log in user.
    $this->container->get('current_user')->setAccount($user);
    $banner_claimant = $banner_builder->buildBanner('node', (int) $this->node->id());
    $this->assertNotEmpty($banner_claimant);
    $this->assertArrayHasKey('content', $banner_claimant);

    // 3. Banner for non-enabled entity bundle returns empty array.
    if (!NodeType::load('page')) {
      NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    }
    $page = Node::create(['type' => 'page', 'title' => 'Page Not Enabled']);
    $page->save();
    $banner_disabled = $banner_builder->buildBanner('node', (int) $page->id());
    $this->assertEmpty($banner_disabled);
  }

}
