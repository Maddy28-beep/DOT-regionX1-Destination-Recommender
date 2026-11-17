<?php

require getcwd().'/vendor/autoload.php';
$summary = \App\Services\Admin\ExpenditureSummary::summarize(collect([
    (object) ['estimated_total_spend' => 'under_10000'], (object) ['estimated_total_spend' => 'under_10000'],
    (object) ['estimated_total_spend' => '10000_20000'], (object) ['estimated_total_spend' => null],
    (object) ['estimated_total_spend' => 'invalid'],
]));
if ($summary['answered'] !== 3 || $summary['unanswered'] !== 2 || $summary['coverage'] !== 60.0 || count($summary['ranges']) !== 5
    || $summary['ranges'][0]['count'] !== 2 || $summary['ranges'][0]['percent'] !== 66.7 || count($summary['leading']) !== 1) {
    throw new RuntimeException('Incorrect expenditure distribution or denominator');
}
$empty = \App\Services\Admin\ExpenditureSummary::summarize(collect(), 4);
if ($empty['answered'] !== 0 || $empty['unanswered'] !== 4 || $empty['leading'] !== [] || count($empty['ranges']) !== 5) {
    throw new RuntimeException('Empty expenditure summary is incorrect');
}
$tied = \App\Services\Admin\ExpenditureSummary::summarize(collect([(object) ['estimated_total_spend' => 'under_10000'], (object) ['estimated_total_spend' => 'over_100000']]));
if (count($tied['leading']) !== 2 || $tied['ranges'][4]['percent'] !== 50.0) {
    throw new RuntimeException('Tied/open-ended expenditure ranges are incorrect');
}
echo 'Expenditure range, denominator, coverage, empty-state and tie checks passed.'.PHP_EOL;
