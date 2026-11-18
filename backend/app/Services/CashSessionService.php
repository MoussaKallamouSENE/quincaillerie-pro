<?php

namespace App\Services;

use App\Exceptions\CashSessionHasRunningRefundException;
use App\Exceptions\NoOpenCashSessionException;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CashSessionService
{
    public function closeFor(User $user, float $closingBalance): CashSession
    {
        return DB::transaction(function () use ($user, $closingBalance): CashSession {
            $cashSession = CashSession::query()
                ->where('user_id', $user->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $cashSession instanceof CashSession) {
                throw new NoOpenCashSessionException;
            }

            if ($cashSession->hasRunningCashRefund()) {
                throw new CashSessionHasRunningRefundException;
            }

            $totals = $cashSession->lockDrawerTotals();

            $cashSession->expected_cash = $totals['expected_cash'];
            $cashSession->closing_balance = $closingBalance;
            $cashSession->closed_at = now();
            $cashSession->status = 'closed';
            $cashSession->save();

            return $cashSession;
        });
    }
}
