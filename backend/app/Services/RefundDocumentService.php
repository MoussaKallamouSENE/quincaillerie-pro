<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Refund;
use App\Models\RefundDocument;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RefundDocumentService
{
    private const DOCUMENTABLE_STATUSES = [
        Refund::STATUS_PENDING,
        Refund::STATUS_BLOCKED,
        Refund::STATUS_APPROVED,
        Refund::STATUS_EXECUTING,
        Refund::STATUS_RECONCILIATION_PENDING,
    ];

    private const DELETABLE_STATUSES = [
        Refund::STATUS_PENDING,
        Refund::STATUS_BLOCKED,
    ];

    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly RefundAccessService $accessService) {}

    public function store(Refund $refund, User $uploader, UploadedFile $file, string $kind): RefundDocument
    {
        $this->assertDocumentable($refund);
        $this->assertKind($kind);

        if (! $file->isValid()) {
            $this->invalidFile();
        }

        $realPath = $file->getRealPath();

        if (! is_string($realPath) || ! is_file($realPath) || ! is_readable($realPath)) {
            $this->invalidFile();
        }

        $size = filesize($realPath);

        if (! is_int($size) || $size < 1) {
            $this->invalidFile();
        }

        if ($size > RefundDocument::MAX_SIZE_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'Le document ne peut pas dépasser 5 Mo.',
            ]);
        }

        $mimeType = $this->detectMime($realPath);
        $extension = self::EXTENSIONS[$mimeType] ?? null;

        if ($extension === null) {
            $this->invalidFile();
        }

        $this->assertContentMatches($mimeType, $realPath, $size);

        $checksum = hash_file('sha256', $realPath);

        if (! is_string($checksum) || $checksum === '') {
            $this->invalidFile();
        }

        if ($this->checksumExists($refund, $checksum)) {
            $this->duplicateDocument();
        }

        $directory = 'refunds/'.$refund->id;
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $directory.'/'.$filename;
        $storedPath = Storage::disk('local')->putFileAs($directory, $file, $filename);

        if ($storedPath !== $path) {
            if (is_string($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            throw new \RuntimeException('Le document n’a pas pu être enregistré.');
        }

        try {
            $document = DB::transaction(function () use ($refund, $uploader, $kind, $path, $file, $mimeType, $size, $checksum): RefundDocument {
                $lockedRefund = Refund::query()
                    ->lockForUpdate()
                    ->findOrFail($refund->id);

                $this->assertDocumentable($lockedRefund);
                $lockedUploader = $this->lockAuthorizedUploader($lockedRefund, $uploader);

                if ($this->checksumExists($lockedRefund, $checksum)) {
                    $this->duplicateDocument();
                }

                $document = RefundDocument::create([
                    'refund_id' => $lockedRefund->id,
                    'uploaded_by_id' => $lockedUploader->id,
                    'kind' => $kind,
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $this->sanitizeName(
                        $file->getClientOriginalName(),
                        'document.'.self::EXTENSIONS[$mimeType],
                    ),
                    'mime_type' => $mimeType,
                    'size' => $size,
                    'checksum' => $checksum,
                ]);

                $this->recordAudit(
                    $lockedRefund,
                    $lockedUploader,
                    'refund.document.uploaded',
                    null,
                    $this->documentAuditValues($document),
                );

                return $document;
            });
        } catch (UniqueConstraintViolationException) {
            Storage::disk('local')->delete($path);

            $this->duplicateDocument();
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return $document->load('uploadedBy');
    }

    public function download(Refund $refund, RefundDocument $document): BinaryFileResponse
    {
        $this->assertDocumentBelongs($refund, $document);

        $problem = $this->storedFileProblem($refund, $document);

        if ($problem === Response::HTTP_NOT_FOUND) {
            abort(Response::HTTP_NOT_FOUND, 'Le document est introuvable.');
        }

        if ($problem !== null) {
            abort(Response::HTTP_CONFLICT, 'L’intégrité du document est compromise.');
        }

        $response = response()->download(
            Storage::disk('local')->path($document->path),
            $this->sanitizeName(
                (string) $document->original_name,
                'document.'.self::EXTENSIONS[$document->mime_type],
            ),
            ['Content-Type' => $document->mime_type],
        );

        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function delete(Refund $refund, RefundDocument $document, User $actor): void
    {
        $this->assertDocumentBelongs($refund, $document);

        DB::transaction(function () use ($refund, $document, $actor): void {
            $lockedRefund = Refund::query()
                ->lockForUpdate()
                ->findOrFail($refund->id);

            $lockedDocument = RefundDocument::query()
                ->where('refund_id', $lockedRefund->id)
                ->lockForUpdate()
                ->findOrFail($document->id);

            $lockedActor = $this->lockActiveUser($actor);

            if (! $this->accessService->canDelete($lockedActor, $lockedRefund, $lockedDocument)) {
                abort(Response::HTTP_FORBIDDEN, 'Vous ne pouvez pas supprimer ce document.');
            }

            if (! in_array($lockedRefund->status, self::DELETABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'document' => 'Ce document ne peut plus être supprimé.',
                ]);
            }

            $this->recordAudit(
                $lockedRefund,
                $lockedActor,
                'refund.document.deleted',
                $this->documentAuditValues($lockedDocument),
                null,
            );

            $lockedDocument->delete();
        });

        if ($document->disk === 'local' && $this->storedAbsolutePath($refund, (string) $document->path) !== null) {
            Storage::disk('local')->delete($document->path);
        }
    }

    public function assertProofsForStatus(Refund $refund): void
    {
        if ($refund->status === Refund::STATUS_EXECUTED) {
            $this->assertExecutionProof($refund);

            return;
        }

        if ($refund->status === Refund::STATUS_FAILED_CONFIRMED) {
            $this->assertFailureProof($refund);
        }
    }

    public function assertExecutionProof(Refund $refund): void
    {
        if (! $this->hasValidDocument($refund, [RefundDocument::KIND_EXECUTION_PROOF])) {
            throw ValidationException::withMessages([
                'refund' => 'Une preuve d’exécution valide est obligatoire avant de confirmer le remboursement.',
            ]);
        }
    }

    public function assertFailureProof(Refund $refund): void
    {
        $hasNoTransferProof = $this->hasValidDocument(
            $refund,
            [RefundDocument::KIND_NO_TRANSFER_PROOF],
        );

        $hasReconciliationReport = $this->hasValidDocument(
            $refund,
            [RefundDocument::KIND_RECONCILIATION_REPORT],
        );

        if (! $hasNoTransferProof && ! $hasReconciliationReport) {
            throw ValidationException::withMessages([
                'refund' => 'Une preuve de non-transfert ou un rapport de réconciliation valide est obligatoire.',
            ]);
        }

        $hasReason = trim((string) $refund->failure_reason) !== ''
            || trim((string) $refund->reconciliation_reason) !== '';

        if (! $hasReason) {
            throw ValidationException::withMessages([
                'refund' => 'Le motif de l’échec ou de la réconciliation est obligatoire.',
            ]);
        }
    }

    private function assertDocumentable(Refund $refund): void
    {
        if (! in_array($refund->status, self::DOCUMENTABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'refund' => 'Ce remboursement ne peut plus recevoir de document.',
            ]);
        }
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, RefundDocument::KINDS, true)) {
            throw ValidationException::withMessages([
                'kind' => 'Le type de document est invalide.',
            ]);
        }
    }

    private function assertDocumentBelongs(Refund $refund, RefundDocument $document): void
    {
        if ((int) $document->refund_id !== (int) $refund->id) {
            abort(Response::HTTP_NOT_FOUND, 'Le document est introuvable.');
        }
    }

    private function lockAuthorizedUploader(Refund $refund, User $uploader): User
    {
        $lockedUploader = $this->lockActiveUser($uploader);

        if (! $this->accessService->canUpload($lockedUploader, $refund)) {
            abort(Response::HTTP_FORBIDDEN, 'Vous ne pouvez pas ajouter de document à ce remboursement.');
        }

        return $lockedUploader;
    }

    private function lockActiveUser(User $user): User
    {
        $lockedUser = User::query()
            ->lockForUpdate()
            ->findOrFail($user->id);

        if (! $lockedUser->is_active) {
            abort(Response::HTTP_FORBIDDEN, 'Votre compte a été désactivé. Contactez un administrateur.');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $lockedUser->unsetRelation('roles');
        $lockedUser->unsetRelation('permissions');

        return $lockedUser;
    }

    private function checksumExists(Refund $refund, string $checksum): bool
    {
        return RefundDocument::query()
            ->where('refund_id', $refund->id)
            ->where('checksum', $checksum)
            ->exists();
    }

    private function detectMime(string $realPath): string
    {
        if (! class_exists(\finfo::class)) {
            $this->invalidFile();
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);

        if (! is_string($mimeType)) {
            return '';
        }

        return mb_strtolower(trim(strtok($mimeType, ';') ?: ''));
    }

    private function assertContentMatches(string $mimeType, string $realPath, int $size): void
    {
        if ($mimeType === 'application/pdf') {
            $this->assertValidPdf($realPath, $size);

            return;
        }

        $imageInfo = @getimagesize($realPath);

        if (! is_array($imageInfo) || mb_strtolower((string) ($imageInfo['mime'] ?? '')) !== $mimeType) {
            $this->invalidFile();
        }
    }

    private function assertValidPdf(string $realPath, int $size): void
    {
        $handle = @fopen($realPath, 'rb');

        if (! is_resource($handle)) {
            $this->invalidFile();
        }

        $header = fread($handle, 5);
        $tailLength = min($size, 2048);
        $tail = '';

        if (fseek($handle, $size - $tailLength) === 0) {
            $tail = (string) fread($handle, $tailLength);
        }

        fclose($handle);

        if ($header !== '%PDF-' || ! str_contains($tail, '%%EOF')) {
            $this->invalidFile();
        }
    }

    private function hasValidDocument(Refund $refund, array $kinds): bool
    {
        $documents = RefundDocument::query()
            ->where('refund_id', $refund->id)
            ->whereIn('kind', $kinds)
            ->get();

        foreach ($documents as $document) {
            if ($this->storedFileProblem($refund, $document) === null) {
                return true;
            }
        }

        return false;
    }

    private function storedFileProblem(Refund $refund, RefundDocument $document): ?int
    {
        if ($document->disk !== 'local') {
            return Response::HTTP_NOT_FOUND;
        }

        $path = (string) $document->path;
        $absolutePath = $this->storedAbsolutePath($refund, $path);

        if ($absolutePath === null) {
            return Response::HTTP_NOT_FOUND;
        }

        if (! in_array($document->mime_type, RefundDocument::ALLOWED_MIME_TYPES, true)) {
            return Response::HTTP_CONFLICT;
        }

        $size = filesize($absolutePath);

        if (! is_int($size) || $size !== (int) $document->size) {
            return Response::HTTP_CONFLICT;
        }

        $checksum = hash_file('sha256', $absolutePath);

        if (! is_string($checksum) || ! hash_equals((string) $document->checksum, $checksum)) {
            return Response::HTTP_CONFLICT;
        }

        if ($this->detectMime($absolutePath) !== $document->mime_type) {
            return Response::HTTP_CONFLICT;
        }

        if (self::EXTENSIONS[$document->mime_type] !== pathinfo($path, PATHINFO_EXTENSION)) {
            return Response::HTTP_CONFLICT;
        }

        return null;
    }

    private function storedAbsolutePath(Refund $refund, string $path): ?string
    {
        $uuidPattern = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

        if (preg_match('#^refunds/'.$refund->id.'/'.$uuidPattern.'\.(pdf|jpg|png|webp)$#i', $path) !== 1) {
            return null;
        }

        $root = realpath(Storage::disk('local')->path(''));

        if ($root === false) {
            return null;
        }

        $absolutePath = realpath(Storage::disk('local')->path($path));
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if ($absolutePath === false
            || ! str_starts_with($absolutePath, $rootPrefix)
            || ! is_file($absolutePath)) {
            return null;
        }

        return $absolutePath;
    }

    private function documentAuditValues(RefundDocument $document): array
    {
        return [
            'document_id' => $document->id,
            'kind' => $document->kind,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size' => (int) $document->size,
            'checksum' => $document->checksum,
            'path' => $document->path,
            'uploaded_by_id' => $document->uploaded_by_id,
        ];
    }

    private function recordAudit(
        Refund $refund,
        User $actor,
        string $action,
        ?array $oldValues,
        ?array $newValues,
    ): void {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'model_type' => Refund::class,
            'model_id' => $refund->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }

    private function sanitizeName(string $name, string $fallback): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim($name, " \t\n\r\0\x0B.");

        if ($name === '') {
            return $fallback;
        }

        return mb_substr($name, 0, 255, 'UTF-8');
    }

    private function duplicateDocument(): never
    {
        throw ValidationException::withMessages([
            'file' => 'Ce document a déjà été ajouté à ce remboursement.',
        ]);
    }

    private function invalidFile(): never
    {
        throw ValidationException::withMessages([
            'file' => 'Le fichier doit être un PDF, une image JPEG, PNG ou WebP valide.',
        ]);
    }
}
