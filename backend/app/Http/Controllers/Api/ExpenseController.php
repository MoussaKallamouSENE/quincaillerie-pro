<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\NoOpenCashSessionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ExpenseController extends Controller
{
    /**
     * Display the expenses of the current cash session of the authenticated user.
     *
     * The expenses are scoped to the open session of the user : an expense
     * belongs to the drawer it was paid from. The total of the session is
     * returned along with the list, it is the amount that will be deducted
     * from the theoretical cash at closing.
     *
     * @throws NoOpenCashSessionException Si l'utilisateur n'a pas de session ouverte.
     */
    public function index(Request $request): JsonResponse
    {
        $cashSession = $this->openCashSession($request->user());

        $expenses = Expense::query()
            ->where('cash_session_id', $cashSession->id)
            ->with('user')
            ->latest('date')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => ExpenseResource::collection($expenses)->resolve($request),
            'meta' => [
                'cash_session_id' => $cashSession->id,
                'count' => $expenses->count(),
                'total' => number_format((float) $expenses->sum('amount'), 2, '.', ''),
            ],
        ]);
    }

    /**
     * Store a newly created expense.
     *
     * The expense is always attached to the open cash session of the
     * authenticated user : the `cash_session_id` sent by the client is
     * accepted but never used for the link, so an expense can never be
     * recorded on the drawer of somebody else.
     *
     * The session row is locked while the expense is written : an expense
     * cannot slip in between the computation and the freezing of the
     * theoretical cash when the session is closed.
     *
     * @throws NoOpenCashSessionException Si l'utilisateur n'a pas de session ouverte.
     */
    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $user = $request->user();

        $expense = DB::transaction(function () use ($request, $user): Expense {
            $cashSession = $this->openCashSession($user, lock: true);

            return Expense::create([
                'cash_session_id' => $cashSession->id,
                'user_id' => $user->id,
                'name' => $request->validated('name'),
                'amount' => round((float) $request->validated('amount'), 2),
                'date' => $request->validated('date') ?? now()->toDateString(),
            ]);
        });

        return ExpenseResource::make($expense)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Open cash session of the user.
     *
     * @param  bool  $lock  Lock the session row, to serialize the write with a concurrent closing.
     *
     * @throws NoOpenCashSessionException
     */
    private function openCashSession(User $user, bool $lock = false): CashSession
    {
        $cashSession = CashSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->first();

        if (! $cashSession instanceof CashSession) {
            throw new NoOpenCashSessionException(
                'Aucune session de caisse n’est ouverte. Ouvrez-en une avant d’enregistrer une dépense.',
            );
        }

        return $cashSession;
    }
}
