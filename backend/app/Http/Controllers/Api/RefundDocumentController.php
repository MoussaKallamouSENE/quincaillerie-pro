<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRefundDocumentRequest;
use App\Http\Resources\RefundDocumentResource;
use App\Models\Refund;
use App\Models\RefundDocument;
use App\Models\User;
use App\Services\RefundAccessService;
use App\Services\RefundDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class RefundDocumentController extends Controller
{
    public function __construct(
        private readonly RefundAccessService $accessService,
        private readonly RefundDocumentService $documentService,
    ) {}

    public function index(Request $request, Refund $refund): AnonymousResourceCollection
    {
        $this->assertCanView($request->user(), $refund);

        return RefundDocumentResource::collection(
            $refund->documents()
                ->with('uploadedBy')
                ->latest()
                ->latest('id')
                ->get(),
        );
    }

    public function store(StoreRefundDocumentRequest $request, Refund $refund): JsonResponse
    {
        $document = $this->documentService->store(
            $refund,
            $request->user(),
            $request->file('file'),
            $request->validated('kind'),
        );

        return RefundDocumentResource::make($document)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Refund $refund, RefundDocument $document): JsonResponse
    {
        $this->assertCanView($request->user(), $refund);
        $this->assertDocumentBelongs($refund, $document);

        return RefundDocumentResource::make($document->loadMissing('uploadedBy'))->response();
    }

    public function download(Request $request, Refund $refund, RefundDocument $document): BinaryFileResponse
    {
        $this->assertCanView($request->user(), $refund);

        return $this->documentService->download($refund, $document);
    }

    public function destroy(Request $request, Refund $refund, RefundDocument $document): Response
    {
        $this->assertDocumentBelongs($refund, $document);

        abort_unless(
            $this->accessService->canDelete($request->user(), $refund, $document),
            Response::HTTP_FORBIDDEN,
            'Vous ne pouvez pas supprimer ce document.',
        );

        $this->documentService->delete($refund, $document, $request->user());

        return response()->noContent();
    }

    private function assertCanView(User $user, Refund $refund): void
    {
        abort_unless(
            $this->accessService->canView($user, $refund),
            Response::HTTP_FORBIDDEN,
            'Vous ne pouvez pas consulter ce remboursement.',
        );
    }

    private function assertDocumentBelongs(Refund $refund, RefundDocument $document): void
    {
        abort_unless(
            (int) $document->refund_id === (int) $refund->id,
            Response::HTTP_NOT_FOUND,
            'Le document est introuvable.',
        );
    }
}
