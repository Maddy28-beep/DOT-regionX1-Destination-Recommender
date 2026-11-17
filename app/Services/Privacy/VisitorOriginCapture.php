<?php

namespace App\Services\Privacy;

use App\Http\Middleware\EnsureVisitorToken;
use App\Models\Itinerary;
use App\Models\TouristPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VisitorOriginCapture
{
    public static function record(Request $request, array $answer, bool $generated = false, ?TouristPreference $legacy = null): void
    {
        $day = now(VisitorOriginStats::ZONE)->toDateString();
        $hash = hash_hmac('sha256', 'origin-confirmation|'.$day.'|'.EnsureVisitorToken::get($request), (string) config('app.key'));
        DB::transaction(function () use ($day, $hash, $answer, $generated, $legacy) {
            $identity = ['day' => $day, 'browser_hash' => $hash];
            $created = DB::table('visitor_origin_receipts')->insertOrIgnore($identity + ['answer' => null, 'generated' => false]);
            $receipt = DB::table('visitor_origin_receipts')->where($identity)->lockForUpdate()->first();
            $before = $receipt->answer === null ? null : json_decode($receipt->answer, true, 512, JSON_THROW_ON_ERROR);
            $alreadyGenerated = (bool) $receipt->generated;

            // Adopt an already-counted legacy trip from this session instead of adding its origin again.
            if ($created && $legacy?->created_at?->copy()->timezone(VisitorOriginStats::ZONE)->toDateString() === $day
                && Itinerary::where('preference_id', $legacy->id)->exists()) {
                $before = VisitorOriginStats::answerOf($legacy);
            }
            $nowGenerated = $alreadyGenerated || $generated;
            if ($before !== $answer) {
                if ($before !== null) {
                    self::adjust($day, $before, -1, $alreadyGenerated ? -1 : 0);
                }
                self::adjust($day, $answer, 1, $nowGenerated ? 1 : 0);
            } elseif (! $alreadyGenerated && $nowGenerated) {
                self::adjust($day, $answer, 0, 1);
            }
            DB::table('visitor_origin_receipts')->where($identity)->update([
                'answer' => json_encode($answer, JSON_THROW_ON_ERROR), 'generated' => $nowGenerated,
            ]);
        }, 3);
    }

    private static function adjust(string $day, array $answer, int $responses, int $generated): void
    {
        $key = ['day' => $day, 'answer' => $answer['answer'], 'country_code' => $answer['country'],
            'province' => $answer['province'], 'city' => $answer['city']];
        DB::table(VisitorOriginStats::TABLE)->insertOrIgnore($key + ['visitors' => 0, 'generated_visitors' => 0]);
        foreach (['visitors' => $responses, 'generated_visitors' => $generated] as $column => $amount) {
            $query = DB::table(VisitorOriginStats::TABLE)->where($key);
            if ($amount > 0) { $query->increment($column, $amount); }
            elseif ($amount < 0) { $query->where($column, '>', 0)->decrement($column, -$amount); }
        }
    }
}
