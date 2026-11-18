<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundDocument;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RefundDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    private function authenticate(string $roleName = 'Admin'): User
    {
        $user = $this->userWithRole($roleName);

        Sanctum::actingAs($user);

        return $user;
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->firstOrFail();

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function refundFor(User $requester, ?User $executor = null, string $status = Refund::STATUS_PENDING): Refund
    {
        $sale = Sale::factory()->create([
            'total_amount' => 5000,
            'payment_status' => 'paid',
        ]);

        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $sale->user_id,
            'amount' => 5000,
            'method' => 'cash',
            'reference' => null,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        return Refund::create([
            'reference' => 'REM-'.Str::upper(Str::random(12)),
            'sale_id' => $sale->id,
            'payment_id' => $payment->id,
            'requested_by_id' => $requester->id,
            'executed_by_id' => $executor?->id,
            'amount' => 5000,
            'method' => 'cash',
            'reason' => 'Retour client pour document de test.',
            'status' => $status,
            'idempotency_key' => Str::uuid()->toString(),
            'idempotency_fingerprint' => hash('sha256', Str::uuid()->toString()),
        ]);
    }

    private function pdfContent(int $targetSize = 512): string
    {
        $prefix = "%PDF-1.4\n";
        $suffix = "\n%%EOF\n";
        $padding = max(0, $targetSize - strlen($prefix) - strlen($suffix));

        return $prefix.str_repeat(' ', $padding).$suffix;
    }

    private function upload(
        Refund $refund,
        User $actor,
        ?string $kind = RefundDocument::KIND_EXECUTION_PROOF,
        ?UploadedFile $file = null,
    ) {
        Sanctum::actingAs($actor);

        return $this->post(
            "/api/refunds/{$refund->id}/documents",
            [
                'kind' => $kind,
                'file' => $file ?? UploadedFile::fake()->createWithContent('proof.pdf', $this->pdfContent()),
            ],
            ['Accept' => 'application/json'],
        );
    }

    public function test_guests_cannot_access_refund_documents(): void
    {
        $requester = User::factory()->create();
        $refund = $this->refundFor($requester);

        $this->getJson("/api/refunds/{$refund->id}/documents")->assertUnauthorized();
        $this->postJson("/api/refunds/{$refund->id}/documents", [])->assertUnauthorized();
        $this->getJson("/api/refunds/{$refund->id}/documents/1/download")->assertUnauthorized();
    }

    public function test_a_manager_can_upload_a_pdf_to_private_storage(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);
        $content = $this->pdfContent();

        $response = $this->upload(
            $refund,
            $manager,
            RefundDocument::KIND_EXECUTION_PROOF,
            UploadedFile::fake()->createWithContent('preuve.pdf', $content),
        )->assertCreated()
            ->assertJsonPath('data.refund_id', $refund->id)
            ->assertJsonPath('data.kind', RefundDocument::KIND_EXECUTION_PROOF)
            ->assertJsonPath('data.original_name', 'preuve.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.uploaded_by_id', $manager->id)
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $document = RefundDocument::query()->findOrFail($response->json('data.id'));

        $this->assertSame('local', $document->disk);
        $this->assertSame(hash('sha256', $content), $document->checksum);
        $this->assertSame(strlen($content), $document->size);
        $this->assertMatchesRegularExpression(
            '#^refunds/'.$refund->id.'/[0-9a-f-]{36}\.pdf$#',
            $document->path,
        );
        Storage::disk('local')->assertExists($document->path);
        Storage::disk('public')->assertMissing($document->path);
        $this->assertSame($content, Storage::disk('local')->get($document->path));

        $audit = AuditLog::query()
            ->where('action', 'refund.document.uploaded')
            ->where('model_id', $refund->id)
            ->sole();

        $this->assertSame($manager->id, $audit->user_id);
        $this->assertNull($audit->old_values);
        $this->assertSame($document->checksum, $audit->new_values['checksum']);
        $this->assertSame($document->path, $audit->new_values['path']);

        $this->getJson("/api/refunds/{$refund->id}/documents")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.path');

        $this->getJson("/api/refunds/{$refund->id}/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonMissingPath('data.path');

        $this->getJson("/api/refunds/{$refund->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonMissingPath('data.documents.0.path');
    }

    public function test_allowed_image_types_are_accepted(): void
    {
        $manager = $this->authenticate('Gérant');

        foreach (['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'] as $extension => $mimeType) {
            $refund = $this->refundFor($manager);

            $this->upload(
                $refund,
                $manager,
                RefundDocument::KIND_EXECUTION_PROOF,
                UploadedFile::fake()->image('preuve.'.$extension, 20, 20),
            )->assertCreated()
                ->assertJsonPath('data.mime_type', $mimeType);
        }
    }

    public function test_invalid_or_spoofed_files_are_rejected(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);

        $this->upload(
            $refund,
            $manager,
            RefundDocument::KIND_EXECUTION_PROOF,
            UploadedFile::fake()->createWithContent('fake.pdf', 'Ceci n’est pas un PDF.'),
        )->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->upload(
            $refund,
            $manager,
            RefundDocument::KIND_EXECUTION_PROOF,
            UploadedFile::fake()->create('fake.pdf', 10, 'application/pdf'),
        )->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->upload(
            $refund,
            $manager,
            RefundDocument::KIND_EXECUTION_PROOF,
            UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "invalid";'),
        )->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->assertDatabaseCount('refund_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_documents_are_limited_to_five_megabytes(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);

        $this->upload(
            $refund,
            $manager,
            RefundDocument::KIND_EXECUTION_PROOF,
            UploadedFile::fake()->createWithContent(
                'limite.pdf',
                $this->pdfContent(RefundDocument::MAX_SIZE_BYTES),
            ),
        )->assertCreated();

        $this->upload(
            $refund,
            $manager,
            RefundDocument::KIND_RECONCILIATION_REPORT,
            UploadedFile::fake()->createWithContent(
                'trop-gros.pdf',
                $this->pdfContent(RefundDocument::MAX_SIZE_BYTES + 1),
            ),
        )->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->assertDatabaseCount('refund_documents', 1);
    }

    public function test_upload_requires_a_valid_kind_and_file(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);
        $file = UploadedFile::fake()->createWithContent('proof.pdf', $this->pdfContent());

        Sanctum::actingAs($manager);

        $this->post(
            "/api/refunds/{$refund->id}/documents",
            ['file' => $file],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors(['kind']);

        $this->post(
            "/api/refunds/{$refund->id}/documents",
            ['kind' => RefundDocument::KIND_EXECUTION_PROOF],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->post(
            "/api/refunds/{$refund->id}/documents",
            ['kind' => 'unknown', 'file' => $file],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors(['kind']);

        $this->assertDatabaseCount('refund_documents', 0);
    }

    public function test_duplicate_document_content_is_rejected_for_the_same_refund(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);
        $content = $this->pdfContent();

        $this->upload(
            $refund,
            $manager,
            RefundDocument::KIND_EXECUTION_PROOF,
            UploadedFile::fake()->createWithContent('preuve.pdf', $content),
        )->assertCreated();

        $this->upload(
            $refund,
            $manager,
            RefundDocument::KIND_RECONCILIATION_REPORT,
            UploadedFile::fake()->createWithContent('copie.pdf', $content),
        )->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->assertDatabaseCount('refund_documents', 1);
    }

    public function test_managers_requester_and_executor_can_access_documents(): void
    {
        $requester = $this->userWithRole('Employé');
        $executor = $this->userWithRole('Employé');
        $otherEmployee = $this->userWithRole('Employé');
        $refund = $this->refundFor($requester, $executor);
        $manager = $this->authenticate('Gérant');
        $document = $this->upload($refund, $manager)->assertCreated()->json('data');

        foreach ([$manager, $requester, $executor] as $user) {
            Sanctum::actingAs($user);

            $this->getJson("/api/refunds/{$refund->id}/documents")
                ->assertOk()
                ->assertJsonCount(1, 'data');

            $this->getJson("/api/refunds/{$refund->id}/documents/{$document['id']}")
                ->assertOk()
                ->assertJsonPath('data.id', $document['id']);

            $this->getJson("/api/refunds/{$refund->id}/documents/{$document['id']}/download")
                ->assertOk()
                ->assertDownload('proof.pdf');
        }

        Sanctum::actingAs($otherEmployee);

        $this->getJson("/api/refunds/{$refund->id}/documents")->assertForbidden();
        $this->getJson("/api/refunds/{$refund->id}/documents/{$document['id']}")->assertForbidden();
        $this->getJson("/api/refunds/{$refund->id}/documents/{$document['id']}/download")->assertForbidden();
    }

    public function test_requester_and_executor_can_upload_but_an_unrelated_employee_cannot(): void
    {
        $requester = $this->userWithRole('Employé');
        $executor = $this->userWithRole('Employé');
        $otherEmployee = $this->userWithRole('Employé');
        $refund = $this->refundFor($requester, $executor);

        $this->upload(
            $refund,
            $requester,
            RefundDocument::KIND_EXECUTION_PROOF,
            UploadedFile::fake()->createWithContent('requester.pdf', $this->pdfContent(600)),
        )->assertCreated();

        $this->upload(
            $refund,
            $executor,
            RefundDocument::KIND_RECONCILIATION_REPORT,
            UploadedFile::fake()->image('executor.png', 20, 20),
        )->assertCreated();

        $this->upload(
            $refund,
            $otherEmployee,
            RefundDocument::KIND_EXECUTION_PROOF,
            UploadedFile::fake()->createWithContent('other.pdf', $this->pdfContent(700)),
        )->assertForbidden();

        $this->assertDatabaseCount('refund_documents', 2);
    }

    public function test_upload_is_rejected_for_a_terminal_refund(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);

        $refund->forceFill(['status' => Refund::STATUS_REJECTED, 'rejected_at' => now()])->save();

        $this->upload($refund, $manager)->assertUnprocessable()->assertJsonValidationErrors(['refund']);

        $this->assertDatabaseCount('refund_documents', 0);
    }

    public function test_document_download_is_private_and_checks_integrity(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);
        $content = $this->pdfContent();
        $documentId = $this->upload(
            $refund,
            $manager,
            RefundDocument::KIND_EXECUTION_PROOF,
            UploadedFile::fake()->createWithContent('proof.pdf', $content),
        )->assertCreated()->json('data.id');

        $document = RefundDocument::query()->findOrFail($documentId);

        $response = $this->get("/api/refunds/{$refund->id}/documents/{$document->id}/download", [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeaderContains('cache-control', 'private')
            ->assertHeaderContains('cache-control', 'no-store')
            ->assertDownload('proof.pdf');

        $this->assertSame($content, $response->streamedContent());

        Storage::disk('local')->put($document->path, 'contenu altéré');

        $this->getJson("/api/refunds/{$refund->id}/documents/{$document->id}/download")
            ->assertStatus(409);
    }

    public function test_missing_document_file_returns_not_found(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);
        $documentId = $this->upload($refund, $manager)->assertCreated()->json('data.id');
        $document = RefundDocument::query()->findOrFail($documentId);

        Storage::disk('local')->delete($document->path);

        $this->getJson("/api/refunds/{$refund->id}/documents/{$document->id}/download")
            ->assertNotFound();
    }

    public function test_document_must_belong_to_the_requested_refund(): void
    {
        $manager = $this->authenticate('Gérant');
        $firstRefund = $this->refundFor($manager);
        $secondRefund = $this->refundFor($manager);
        $documentId = $this->upload($firstRefund, $manager)->assertCreated()->json('data.id');

        $this->getJson("/api/refunds/{$secondRefund->id}/documents/{$documentId}")->assertNotFound();
        $this->getJson("/api/refunds/{$secondRefund->id}/documents/{$documentId}/download")->assertNotFound();
        $this->deleteJson("/api/refunds/{$secondRefund->id}/documents/{$documentId}")->assertNotFound();
    }

    public function test_path_traversal_metadata_cannot_be_downloaded(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);

        $document = RefundDocument::create([
            'refund_id' => $refund->id,
            'uploaded_by_id' => $manager->id,
            'kind' => RefundDocument::KIND_EXECUTION_PROOF,
            'disk' => 'local',
            'path' => 'refunds/'.$refund->id.'/../../secret.pdf',
            'original_name' => 'secret.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'checksum' => str_repeat('a', 64),
        ]);

        $this->getJson("/api/refunds/{$refund->id}/documents/{$document->id}/download")
            ->assertNotFound();

        Storage::disk('local')->put('secret.txt', 'secret');

        $this->deleteJson("/api/refunds/{$refund->id}/documents/{$document->id}")
            ->assertNoContent();

        Storage::disk('local')->assertExists('secret.txt');
        $this->assertDatabaseMissing('refund_documents', ['id' => $document->id]);
    }

    public function test_a_manager_can_delete_a_pending_document_with_an_audit_trail(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);
        $documentId = $this->upload($refund, $manager)->assertCreated()->json('data.id');
        $document = RefundDocument::query()->findOrFail($documentId);

        $this->deleteJson("/api/refunds/{$refund->id}/documents/{$document->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('refund_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->path);

        $audit = AuditLog::query()
            ->where('action', 'refund.document.deleted')
            ->where('model_id', $refund->id)
            ->sole();

        $this->assertSame($manager->id, $audit->user_id);
        $this->assertSame($document->path, $audit->old_values['path']);
        $this->assertSame($document->checksum, $audit->old_values['checksum']);
        $this->assertNull($audit->new_values);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'refund.document.uploaded')
            ->where('model_id', $refund->id)
            ->count());
    }

    public function test_documents_cannot_be_deleted_after_approval(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);
        $documentId = $this->upload($refund, $manager)->assertCreated()->json('data.id');
        $document = RefundDocument::query()->findOrFail($documentId);

        $refund->forceFill([
            'status' => Refund::STATUS_APPROVED,
            'approved_by_id' => $manager->id,
            'approved_at' => now(),
        ])->save();

        $this->deleteJson("/api/refunds/{$refund->id}/documents/{$document->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document']);

        $this->assertDatabaseHas('refund_documents', ['id' => $document->id]);
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_executed_status_requires_a_valid_execution_proof(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);

        try {
            $refund->forceFill([
                'status' => Refund::STATUS_EXECUTED,
                'executed_by_id' => $manager->id,
                'executed_at' => now(),
            ])->save();
            $this->fail('Une preuve d’exécution aurait dû être exigée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refund', $exception->errors());
        }

        $this->assertSame(Refund::STATUS_PENDING, $refund->fresh()->status);

        $this->upload($refund, $manager, RefundDocument::KIND_EXECUTION_PROOF)->assertCreated();

        $refund->refresh()->forceFill([
            'status' => Refund::STATUS_EXECUTED,
            'executed_by_id' => $manager->id,
            'executed_at' => now(),
        ])->save();

        $this->assertSame(Refund::STATUS_EXECUTED, $refund->fresh()->status);
    }

    public function test_failed_confirmation_requires_a_valid_proof_and_reason(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);

        try {
            $refund->forceFill([
                'status' => Refund::STATUS_FAILED_CONFIRMED,
                'failure_reason' => 'Transfert impossible.',
                'failed_at' => now(),
            ])->save();
            $this->fail('Une preuve de non-transfert aurait dû être exigée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refund', $exception->errors());
        }

        $this->assertSame(Refund::STATUS_PENDING, $refund->fresh()->status);

        $this->upload($refund, $manager, RefundDocument::KIND_NO_TRANSFER_PROOF)->assertCreated();

        $refund->refresh()->forceFill([
            'status' => Refund::STATUS_FAILED_CONFIRMED,
            'failure_reason' => 'Transfert impossible.',
            'failed_at' => now(),
        ])->save();

        $this->assertSame(Refund::STATUS_FAILED_CONFIRMED, $refund->fresh()->status);
    }

    public function test_failed_confirmation_requires_a_reason_even_with_a_report(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);

        $this->upload($refund, $manager, RefundDocument::KIND_RECONCILIATION_REPORT)->assertCreated();

        try {
            $refund->refresh()->forceFill([
                'status' => Refund::STATUS_FAILED_CONFIRMED,
                'failed_at' => now(),
            ])->save();
            $this->fail('Un motif aurait dû être exigé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refund', $exception->errors());
        }

        $refund->refresh()->forceFill([
            'status' => Refund::STATUS_FAILED_CONFIRMED,
            'reconciliation_reason' => 'Rapprochement bancaire négatif.',
            'failed_at' => now(),
        ])->save();

        $this->assertSame(Refund::STATUS_FAILED_CONFIRMED, $refund->fresh()->status);
    }

    public function test_terminal_status_requires_the_proof_file_to_still_exist(): void
    {
        $manager = $this->authenticate('Gérant');
        $refund = $this->refundFor($manager);
        $documentId = $this->upload($refund, $manager)->assertCreated()->json('data.id');
        $document = RefundDocument::query()->findOrFail($documentId);

        Storage::disk('local')->delete($document->path);

        try {
            $refund->forceFill([
                'status' => Refund::STATUS_EXECUTED,
                'executed_by_id' => $manager->id,
                'executed_at' => now(),
            ])->save();
            $this->fail('Une preuve physique valide aurait dû être exigée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refund', $exception->errors());
        }

        $this->assertSame(Refund::STATUS_PENDING, $refund->fresh()->status);
    }
}
