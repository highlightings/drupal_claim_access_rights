<?php

declare(strict_types=1);

namespace Drupal\Tests\claim_access_rights\Kernel;

use Drupal\claim_access_rights\ClaimAccessManagerInterface;
use Drupal\claim_access_rights\ClaimSubmissionProcessor;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Covers the single approval path shared by every claim-granting entry point.
 *
 * These specifically guard against the immediate-mode approval gap: granting
 * access must require the 'claim access rights' permission regardless of
 * which of the three entry points (immediate handler, ECA action, manual
 * approval) triggered it.
 *
 * @group claim_access_rights
 */
final class ClaimSubmissionProcessorTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'webform', 'webform_ui',
    'eca', 'eca_content', 'eca_ui', 'bpmn_io', 'claim_access_rights',
  ];

  private ClaimSubmissionProcessor $processor;
  private ClaimAccessManagerInterface $manager;
  private Node $node;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('webform_submission');
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
    $this->processor = $this->container->get('claim_access_rights.submission_processor');
    $this->manager = $this->container->get('claim_access_rights.manager');
  }

  /**
   * Builds a claim_listing submission owned by the given user.
   */
  private function createSubmission(\Drupal\user\UserInterface $owner, array $data_overrides = []): WebformSubmission {
    $data = array_merge([
      'target_entity_type' => 'node',
      'target_entity_id' => (int) $this->node->id(),
      'requested_rights' => ['view', 'edit'],
      'start_date' => date('Y-m-d'),
      'end_date' => date('Y-m-d', time() + 30 * 86400),
      'no_end_date' => 0,
      'claim_notes' => 'test claim',
    ], $data_overrides);

    $submission = WebformSubmission::create([
      'webform_id' => ClaimSubmissionProcessor::WEBFORM_ID,
      'uid' => $owner->id(),
      'data' => $data,
    ]);
    $submission->save();
    return $submission;
  }

  public function testApprovalRequiresTheClaimPermission(): void {
    // No 'claim access rights' permission: this is the exact gap that let
    // immediate-approval mode grant access to any authenticated user.
    $unprivileged = $this->createUser([]);
    $submission = $this->createSubmission($unprivileged);

    $this->expectException(\InvalidArgumentException::class);
    $this->processor->approve($submission);
  }

  public function testApprovalSucceedsForAPermittedUser(): void {
    $user = $this->createUser(['claim access rights']);
    $submission = $this->createSubmission($user);

    $grant_id = $this->processor->approve($submission);
    $grant = $this->manager->getGrant($grant_id);

    $this->assertSame((int) $user->id(), (int) $grant['uid']);
    $this->assertSame((int) $submission->id(), (int) $grant['submission_id']);
    $this->assertSame('active', $grant['status']);
  }

  public function testApprovalRevalidatesTheWindowEvenIfTheFormDidNot(): void {
    // A submission created outside the form (API, import) never passed
    // ClaimAccessWebformHandler::validateForm(), so approve() must not trust
    // it: a window beyond max_claim_days (90) must still be rejected by
    // grantAccess()'s own internal re-validation.
    $user = $this->createUser(['claim access rights']);
    $submission = $this->createSubmission($user, [
      'end_date' => date('Y-m-d', time() + 400 * 86400),
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->processor->approve($submission);
  }

  public function testApprovalRejectsANonClaimListingSubmission(): void {
    $user = $this->createUser(['claim access rights']);
    $submission = WebformSubmission::create([
      'webform_id' => ClaimSubmissionProcessor::WEBFORM_ID . '_other',
      'uid' => $user->id(),
      'data' => [],
    ]);
    // Not saved as a real webform (would fail without the bundle existing);
    // approve() must reject on the ID check before touching storage.
    $this->expectException(\InvalidArgumentException::class);
    $this->processor->approve($submission);
  }

}
