<?php

namespace App\Http\Controllers;

use App\Actions\ManageFinance;
use App\Http\Requests\FinanceEntryRequest;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    public function __construct(private ManageFinance $finance) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'type' => ['nullable', 'in:income,expense'],
        ]);
        $month = $filters['month'] ?? now()->format('Y-m');
        $type = $filters['type'] ?? 'all';
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = $start->endOfMonth();
        $base = $this->finance->query($request->user())->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()]);
        $incomeCents = (int) (clone $base)->where('type', 'income')->sum('amount_cents');
        $expenseCents = (int) (clone $base)->where('type', 'expense')->sum('amount_cents');
        $entries = (clone $base)->when($type !== 'all', fn ($query) => $query->where('type', $type))
            ->orderByDesc('occurred_on')->orderByDesc('id')->paginate(12)->withQueryString();

        // O gráfico mostra valores agregados reais dos seis meses, sem estimativas.
        $chartStart = $start->subMonths(5)->startOfMonth();
        $monthExpression = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', occurred_on)" : "DATE_FORMAT(occurred_on, '%Y-%m')";
        $monthly = $this->finance->query($request->user())->whereBetween('occurred_on', [$chartStart->toDateString(), $end->toDateString()])
            ->selectRaw($monthExpression.' as month_key, type, SUM(amount_cents) as total_cents')
            ->groupBy('month_key', 'type')->get()->groupBy('month_key');
        $chart = collect(range(0, 5))->map(function (int $offset) use ($chartStart, $monthly) {
            $date = $chartStart->addMonths($offset);
            $rows = $monthly->get($date->format('Y-m'), collect());

            return [
                'label' => $date->translatedFormat('M'),
                'income' => (int) ($rows->firstWhere('type', 'income')->total_cents ?? 0),
                'expense' => (int) ($rows->firstWhere('type', 'expense')->total_cents ?? 0),
            ];
        });
        $chartMax = max(1, (int) $chart->flatMap(fn ($row) => [$row['income'], $row['expense']])->max());

        $incomeLabel = Money::format($incomeCents);
        $expenseLabel = Money::format($expenseCents);
        $balanceLabel = Money::format($incomeCents - $expenseCents);
        $chart = $chart->map(fn ($row) => $row + ['income_label' => Money::format($row['income']), 'expense_label' => Money::format($row['expense'])]);

        return view('finance.index', compact('entries', 'month', 'type', 'incomeCents', 'expenseCents', 'incomeLabel', 'expenseLabel', 'balanceLabel', 'chart', 'chartMax'));
    }

    public function create()
    {
        return view('finance.create');
    }

    public function store(FinanceEntryRequest $request)
    {
        $entry = $this->finance->create($request->user(), $request->validated());

        return redirect()->route('finance.index', ['month' => $entry->occurred_on->format('Y-m')])->with('status', 'Lançamento registrado.');
    }
}
