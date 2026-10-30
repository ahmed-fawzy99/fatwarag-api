<?php

namespace App\Filament\Widgets;

use App\Models\AiUsage;
use App\Services\AiSpend;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * This month's AI spend against the budget, split by purpose. One-off indexing (embeddings) is tracked but isn't
 * part of the budget, so it's shown on its own.
 */
class AiSpendWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'AI spend this month';

    protected static ?int $sort = 1;

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $spend = app(AiSpend::class);
        $spent = $spend->spentThisMonth();
        $budget = $spend->monthlyBudget();
        $percent = $budget > 0 ? $spent / $budget * 100 : 100;

        $stats = [
            Stat::make('Spent of budget', '$'.number_format($spent, 2))
                ->description(sprintf('of $%s budget (%s%%)', number_format($budget, 2), number_format($percent, 0)))
                ->color($percent >= 100 ? 'danger' : ($percent >= 80 ? 'warning' : 'success')),
        ];

        $byPurpose = AiUsage::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('purpose, counts_toward_budget, sum(cost_usd) as cost, count(*) as calls')
            ->groupBy('purpose', 'counts_toward_budget')
            ->orderByDesc('cost')
            ->get();

        foreach ($byPurpose->where('counts_toward_budget', true) as $row) {
            $stats[] = Stat::make(ucfirst($row->purpose), '$'.number_format((float) $row->cost, 2))
                ->description(number_format($row->calls).' calls');
        }

        $oneOff = (float) $byPurpose->where('counts_toward_budget', false)->sum('cost');

        if ($oneOff > 0) {
            $stats[] = Stat::make('Indexing (not budgeted)', '$'.number_format($oneOff, 2))->description('One-off, tracked only');
        }

        return $stats;
    }
}
