<?php

namespace App\Http\Controllers;

use App\Services\PriceAnalytics;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Portal publik: tanpa login, hanya menampilkan harga yang sudah diverifikasi dan diterbitkan.
 *
 * Aturan keamanan:
 *  - semua angka berasal dari PriceAnalytics::forPublic() (status "published", belum ditarik)
 *  - hanya kolom yang aman dipilih di sana: tidak ada nama petugas, keterangan sumber, catatan,
 *    alasan penolakan, atau penanda lonjakan
 *  - parameter yang tidak valid diabaikan, bukan menimbulkan galat
 */
class PublicPortalController extends Controller
{
    private const PERIODS = [7, 30, 90];

    private const LOOKBACK_DAYS = 60;

    public function index(Request $request): View
    {
        $analytics = PriceAnalytics::forPublic();
        $today = today();

        $days = $request->integer('rentang', 30);
        if (! in_array($days, self::PERIODS, true)) {
            $days = 30;
        }
        $from = $today->copy()->subDays($days - 1);

        $overview = $analytics->overview($today, self::LOOKBACK_DAYS);

        // Komoditas yang diminta hanya diterima bila memang punya data terbit; selain itu pakai yang pertama.
        $selected = $overview->firstWhere('commodity_id', $request->integer('komoditas')) ?? $overview->first();

        $trend = collect();
        $markets = collect();

        if ($selected) {
            $trend = $analytics->trend($selected['commodity_id'], $from, $today);
            $markets = $analytics->latestByMarket($selected['commodity_id'], $today->copy()->subDays(self::LOOKBACK_DAYS), $today);
        }

        return view('public.prices', [
            'days'      => $days,
            'periods'   => self::PERIODS,
            'overview'  => $overview,
            'selected'  => $selected,
            'trend'     => $trend,
            'markets'   => $markets,
            'chart'     => PriceAnalytics::chartPayload($trend),
            'latestDay' => $overview->max('day'),
        ]);
    }
}
