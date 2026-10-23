<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsCredentialProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class XlwmsCredentialController
{
    public function index(): Response
    {
        return Inertia::render('Logistics/Integrations/XlwmsCredential', [
            'probeEnabled' => (bool) config('xlwms.manual_probe_enabled', false),
            'endpoint' => 'https://api.xlwms.com/openapi/v1/warehouse/options',
        ]);
    }

    public function verify(Request $request, XlwmsCredentialProbe $probe): JsonResponse
    {
        // POST JSON only; never flash/repopulate credentials via redirect or session.
        $data = $request->validate([
            'app_key' => ['required', 'string', 'min:8', 'max:256'],
            'app_secret' => ['required', 'string', 'min:8', 'max:256'],
        ]);

        $result = $probe->verify(trim($data['app_key']), trim($data['app_secret']));

        return response()->json($result)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
