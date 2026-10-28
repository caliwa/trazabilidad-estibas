<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;

class ZebraPreviewController extends Controller
{
    /**
     * Proxies ZPL code to Labelary API to avoid CORS issues and provide caching/fallback.
     */
    public function preview(Request $request)
    {
        $zpl = $request->input('zpl');
        $dpmm = $request->input('dpmm', '8dpmm'); // 203 DPI = 8dpmm
        $width = $request->input('width', '4');    // 4 inches = 100mm
        $height = $request->input('height', '3.15'); // 3.15 inches = 80mm

        if (empty($zpl)) {
            return response()->json(['error' => 'No se proporcionó código ZPL'], 400);
        }

        $cacheKey = 'zpl_prev_' . md5($zpl . $dpmm . $width . $height);
        $cachedPng = Cache::get($cacheKey);
        if ($cachedPng !== null) {
            return Response::make($cachedPng, 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        try {
            $url = "http://api.labelary.com/v1/printers/{$dpmm}/labels/{$width}x{$height}/0/";
            $response = Http::withHeaders([
                'Accept' => 'image/png',
            ])->withBody($zpl, 'application/x-www-form-urlencoded')
              ->timeout(8)
              ->post($url);

            if ($response->successful()) {
                Cache::put($cacheKey, $response->body(), now()->addDay());

                return Response::make($response->body(), 200, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }

            return response()->json([
                'error' => 'Labelary API retornó error: ' . $response->status(),
                'details' => $response->body()
            ], $response->status());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Fallo al conectar con el motor de renderizado ZPL',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
