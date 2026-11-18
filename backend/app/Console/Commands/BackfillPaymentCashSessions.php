<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('payments:backfill-cash-sessions
    {--dry-run : Affiche les paiements qui seraient mis à jour sans écrire}
    {--execute : Applique le backfill}
    {--before= : Limite aux paiements créés avant cette date}')]
#[Description('Rattache les anciens paiements espèces à la session d’origine de leur vente')]
class BackfillPaymentCashSessions extends Command
{
    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Utilisez soit --dry-run, soit --execute.');

            return self::FAILURE;
        }

        $before = $this->option('before');
        $beforeAt = null;

        if (is_string($before) && $before !== '') {
            try {
                $beforeAt = CarbonImmutable::parse($before);
            } catch (Throwable) {
                $this->error('La date fournie à --before est invalide.');

                return self::FAILURE;
            }
        }

        $query = $this->eligiblePaymentsQuery($beforeAt);
        $ids = (clone $query)
            ->orderBy('payments.id')
            ->pluck('payments.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if (! $this->option('execute')) {
            $this->line('Paiements éligibles: '.count($ids));

            if ($ids !== []) {
                $rows = (clone $query)
                    ->whereIn('payments.id', array_slice($ids, 0, 50))
                    ->select([
                        'payments.id',
                        'payments.sale_id',
                        'sales.cash_session_id',
                        'payments.amount',
                        'payments.created_at',
                    ])
                    ->orderBy('payments.id')
                    ->get();

                $this->table(
                    ['payment_id', 'sale_id', 'cash_session_id', 'amount', 'created_at'],
                    $rows->map(fn (object $row): array => (array) $row)->all(),
                );
            }

            $this->line('Mode dry-run : aucune écriture.');

            return self::SUCCESS;
        }

        $updated = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $updated += DB::transaction(function () use ($beforeAt, $chunk): int {
                $rows = $this->eligiblePaymentsQuery($beforeAt)
                    ->whereIn('payments.id', $chunk)
                    ->select([
                        'payments.id',
                        'payments.sale_id',
                        'sales.cash_session_id',
                    ])
                    ->orderBy('payments.id')
                    ->get();

                $count = 0;

                foreach ($rows as $row) {
                    $count += DB::table('payments')
                        ->where('id', $row->id)
                        ->where('sale_id', $row->sale_id)
                        ->whereNull('cash_session_id')
                        ->update(['cash_session_id' => $row->cash_session_id]);
                }

                return $count;
            });
        }

        $this->line('Paiements éligibles: '.count($ids));
        $this->line('Paiements mis à jour: '.$updated);

        return self::SUCCESS;
    }

    private function eligiblePaymentsQuery(?CarbonInterface $before): Builder
    {
        $query = DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->join('cash_sessions', 'cash_sessions.id', '=', 'sales.cash_session_id')
            ->where('payments.method', 'cash')
            ->whereNull('payments.cash_session_id')
            ->where(function (Builder $query): void {
                $query->where('cash_sessions.status', 'open')
                    ->orWhere(function (Builder $query): void {
                        $query->whereNotNull('cash_sessions.closed_at')
                            ->whereColumn('payments.created_at', '<', 'cash_sessions.closed_at');
                    });
            });

        if ($before instanceof CarbonInterface) {
            $query->where('payments.created_at', '<', $before);
        }

        return $query;
    }
}
