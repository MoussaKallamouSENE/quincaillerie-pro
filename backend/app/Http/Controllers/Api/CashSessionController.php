<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CashSessionAlreadyOpenException;
use App\Exceptions\CashSessionHasRunningRefundException;
use App\Exceptions\NoOpenCashSessionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloseCashSessionRequest;
use App\Http\Requests\OpenCashSessionRequest;
use App\Http\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Models\User;
use App\Services\CashSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CashSessionController extends Controller
{
    public function __construct(private readonly CashSessionService $cashSessionService) {}

    /**
     * Open a cash session for the authenticated user.
     *
     * A user can only hold one open session at a time : opening a second one
     * is refused with a conflict.
     *
     * @throws CashSessionAlreadyOpenException
     */
    public function open(OpenCashSessionRequest $request): JsonResponse
    {
        $cashSession = DB::transaction(function () use ($request): CashSession {
            $userId = (int) $request->user()->getAuthIdentifier();

            // L'utilisateur serialise les ouvertures concurrentes. Aucun
            // verrou n'est pris sur cash_sessions : une execution especes
            // verrouille la session puis l'utilisateur, donc attendre une
            // session tout en tenant l'utilisateur creerait un cycle.
            $user = User::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail();

            // Relecture sans verrou : le verrou utilisateur garantit qu'aucune
            // autre ouverture ne peut creer de session entre-temps.
            $existing = CashSession::query()
                ->where('user_id', $user->id)
                ->where('status', 'open')
                ->first();

            if ($existing instanceof CashSession) {
                throw new CashSessionAlreadyOpenException;
            }

            return CashSession::create([
                'user_id' => $user->id,
                'opening_balance' => $request->validated('opening_balance'),
                'status' => 'open',
                'opened_at' => now(),
            ]);
        });

        return CashSessionResource::make($cashSession)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Close the cash session of the authenticated user.
     *
     * The counted amount is stored on the session, its status becomes "closed"
     * and its closing time is recorded. The theoretical cash of the drawer
     * (opening balance + cash sales - expenses - cash refunds) is frozen at
     * this exact moment, so a later expense cannot rewrite the history of the
     * session. The resource exposes the difference between the counted cash
     * and that theoretical cash.
     *
     * @throws NoOpenCashSessionException
     * @throws CashSessionHasRunningRefundException
     */
    public function close(CloseCashSessionRequest $request): JsonResponse
    {
        $cashSession = $this->cashSessionService->closeFor(
            $request->user(),
            (float) $request->validated('closing_balance'),
        );

        return CashSessionResource::make($cashSession)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Display the open cash session of the authenticated user, if any.
     */
    public function current(Request $request): JsonResponse
    {
        $cashSession = CashSession::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        return response()->json([
            'data' => $cashSession instanceof CashSession
                ? CashSessionResource::make($cashSession)->resolve($request)
                : null,
        ]);
    }
}
