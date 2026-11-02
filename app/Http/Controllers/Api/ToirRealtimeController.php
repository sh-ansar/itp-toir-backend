<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ToirRealtimeController extends Controller
{
    public function config(Request $request): JsonResponse
    {
        return response()->json([
            'enabled'=>config('broadcasting.default')==='reverb'
                && filled(config('broadcasting.connections.reverb.key')),
            'key'=>config('broadcasting.connections.reverb.key'),
            'path'=>'/mro/ws',
            'host'=>parse_url((string) config('app.url'), PHP_URL_HOST) ?: $request->getHost(),
            'auth_endpoint'=>'/mro/api/v1/broadcasting/auth',
            'presence_channel'=>'toir.company.'.(int)$request->session()->get('toir_company_id').'.presence',
            'channel'=>'toir.company.'.(int)$request->session()->get('toir_company_id')
                .'.user.'.$request->user()->id,
        ])->header('Cache-Control','private, no-store');
    }
}
