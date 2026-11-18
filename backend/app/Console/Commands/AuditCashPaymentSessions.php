<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

#[Signature('payments:cash-session-audit {--post-close : Affiche le détail des cas post-clôture}')]
#[Description('Audite en lecture seule les paiements espèces et leur session de caisse')]
class AuditCashPaymentSessions extends Command
{
    public function handle(): int
    {
        $nullPostClose = (clone $this->baseQuery())
            ->whereNull('payments.cash_session_id')
            ->whereNotNull('cash_sessions.closed_at')
            ->whereColumn('payments.created_at', '>=', 'cash_sessions.closed_at')
            ->count();

        $attributedAfterClose = (clone $this->baseQuery())
            ->join('cash_sessions as attributed_sessions', 'attributed_sessions.id', '=', 'payments.cash_session_id')
            ->whereNotNull('payments.cash_session_id')
            ->whereNotNull('attributed_sessions.closed_at')
            ->whereColumn('payments.created_at', '>=', 'attributed_sessions.closed_at')
            ->count();

        $originAfterClose = (clone $this->baseQuery())
            ->whereNotNull('cash_sessions.closed_at')
            ->whereColumn('payments.created_at', '>=', 'cash_sessions.closed_at')
            ->count();

        $legacyUserMismatch = (clone $this->baseQuery())
            ->join('cash_sessions as attributed_sessions', 'attributed_sessions.id', '=', 'payments.cash_session_id')
            ->whereNotNull('payments.cash_session_id')
            ->whereColumn('payments.user_id', '!=', 'attributed_sessions.user_id')
            ->count();

        $this->line('null_post_close_origin: '.$nullPostClose);
        $this->line('attributed_after_close: '.$attributedAfterClose);
        $this->line('origin_after_close: '.$originAfterClose);
        $this->line('legacy_user_mismatch: '.$legacyUserMismatch);

        if ($this->option('post-close')) {
            $this->renderDetails('null_post_close_origin', (clone $this->baseQuery())
                ->whereNull('payments.cash_session_id')
                ->whereNotNull('cash_sessions.closed_at')
                ->whereColumn('payments.created_at', '>=', 'cash_sessions.closed_at'));

            $this->renderDetails('attributed_after_close', (clone $this->baseQuery())
                ->join('cash_sessions as attributed_sessions', 'attributed_sessions.id', '=', 'payments.cash_session_id')
                ->whereNotNull('payments.cash_session_id')
                ->whereNotNull('attributed_sessions.closed_at')
                ->whereColumn('payments.created_at', '>=', 'attributed_sessions.closed_at'));
        }

        return self::SUCCESS;
    }

    private function baseQuery(): Builder
    {
        return DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->join('cash_sessions', 'cash_sessions.id', '=', 'sales.cash_session_id')
            ->where('payments.method', 'cash');
    }

    private function renderDetails(string $label, Builder $query): void
    {
        $rows = $query
            ->select([
                'payments.id',
                'payments.sale_id',
                'sales.cash_session_id as origin_session_id',
                'payments.cash_session_id as attributed_session_id',
                'payments.user_id',
                'payments.amount',
                'payments.created_at',
                'cash_sessions.closed_at as origin_closed_at',
            ])
            ->orderBy('payments.id')
            ->limit(100)
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $this->line($label.':');
        $this->table(
            ['payment_id', 'sale_id', 'origin_session_id', 'attributed_session_id', 'user_id', 'amount', 'created_at', 'origin_closed_at'],
            $rows->map(fn (object $row): array => (array) $row)->all(),
        );
    }
}
