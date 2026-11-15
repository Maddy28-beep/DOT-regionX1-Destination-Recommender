<?php

namespace App\Services\Admin;

use App\Http\Controllers\ExitSurveyController;
use Illuminate\Support\Collection;

final class ExpenditureSummary
{
    public static function summarize(Collection $responses, ?int $total = null): array
    {
        $counts = $responses->pluck('estimated_total_spend')->countBy();
        $answered = array_sum(array_map(fn ($key) => (int) $counts->get($key, 0), array_keys(ExitSurveyController::SPEND_BRACKETS)));
        $total = max($answered, $total ?? $responses->count());
        $ranges = [];
        foreach (ExitSurveyController::SPEND_BRACKETS as $key => $label) {
            $n = (int) $counts->get($key, 0);
            $ranges[] = ['key' => $key, 'label' => $label, 'count' => $n, 'percent' => $answered ? round($n / $answered * 100, 1) : 0];
        }
        $max = max(array_column($ranges, 'count'));
        return ['total' => $total, 'answered' => $answered, 'unanswered' => $total - $answered,
            'coverage' => $total ? round($answered / $total * 100, 1) : 0,
            'ranges' => $ranges,
            'leading' => $max ? array_values(array_column(array_filter($ranges, fn ($row) => $row['count'] === $max), 'label')) : [],
        ];
    }
}
