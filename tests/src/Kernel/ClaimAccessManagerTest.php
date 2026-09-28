<?php

declare(strict_types=1);

namespace Drupal\Tests\claim_access_rights\Kernel;

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
    $this->assertTrue(ClaimAccessManager::isExtensionPending($grant['notes']));
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
    $this->assertFalse($this->manager->hasAccess($node2, $user2, 'update'));

    // Pending expired renewal does NOT block other users from claiming exclusive access.
    $user3 = $this->createUser();
    $id3 = $this->manager->grantAccess('node', (int) $node2->id(), (int) $user3->id(), ['view'], 'exclusive', time() + 1000);
    $this->assertGreaterThan(0, $id3);

    // Admin approves/extends the active grant: approval note is recorded.
    $this->assertTrue($this->manager->extendGrant($id, 30, TRUE));
    $updated_grant = $this->manager->getGrant($id);
    $this->assertSame(ClaimAccessManagerInterface::STATUS_ACTIVE, $updated_grant['status']);
    $this->assertFalse(ClaimAccessManager::isExtensionPending($updated_grant['notes']));
    $this->assertTrue($this->manager->hasAccess($this->node, $user, 'update'));
  }

  public function testDifferentModesDoNotBypassExclusiveConflict(): void {
    $u1 = $this->createUser();
    $u2 = $this->createUser();
    $now = time();

    // Grant access under replace mode.
    $this->manager->grantAccess('node', (int) $this->node->id(), (int) $u1->id(), ['view'], 'replace', $now + 5000, NULL, NULL, $now);

    // Another user requesting exclusive access during the same window must be rejected.
    $this->expectException(\RuntimeException::class);
    $this->manager->grantAccess('node', (int) $this->node->id(), (int) $u2->id(), ['view'], 'exclusive', $now + 3000, NULL, NULL, $now + 1000);
  }

}
