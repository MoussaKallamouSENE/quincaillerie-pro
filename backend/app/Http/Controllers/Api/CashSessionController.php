<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CashSessionAlreadyOpenException;
use App\Exceptions\NoOpenCashSessionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloseCashSessionRequest;
use App\Http\Requests\OpenCashSessionRequest;
use App\Http\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CashSessionController extends Controller
{
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
            // Lock the existing parent row so concurrent attempts for the same
            // user are serialized even when no open session row exists yet.
            $user = User::query()
                ->whereKey($request->user()->getAuthIdentifier())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = CashSession::query()
                ->where('user_id', $user->id)
                ->where('status', 'open')
                ->lockForUpdate()
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
     * (opening balance + cash sales - expenses) is frozen at this exact
     * moment, so a later expense cannot rewrite the history of the session.
     * The resource exposes the difference between the counted cash and that
     * theoretical cash.
     *
     * @throws NoOpenCashSessionException
     */
    public function close(CloseCashSessionRequest $request): JsonResponse
    {
        $cashSession = DB::transaction(function () use ($request): CashSession {
            $user = $request->user();

            // The row is locked so that two concurrent closings cannot both
            // close the same session and record two different counts.
            $cashSession = CashSession::query()
                ->where('user_id', $user->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $cashSession instanceof CashSession) {
                throw new NoOpenCashSessionException;
            }

            // Theoretical balance of the drawer, frozen at closing time:
            // opening balance + cash sales - expenses.
            // The balance is written on the session, so an expense recorded
            // later cannot rewrite the history of the closed session.
            $cashSession->expected_cash = $cashSession->computeExpectedCash();

            $cashSession->closing_balance = $request->validated('closing_balance');
            $cashSession->closed_at = now();
            $cashSession->status = 'closed';
            $cashSession->save();

            return $cashSession;
        });

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
