<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', [
            'workspace' => [
                'status' => 'ready',
                'greeting' => 'Welcome back, '.$request->user()->name.'.',
                'correlationId' => (string) Str::uuid(),
                'conversation' => [
                    'status' => 'empty',
                    'messages' => [],
                ],
                'sources' => [],
                'provider' => [
                    'status' => 'deferred',
                    'message' => 'Provider reads are unavailable until their capability contracts are approved.',
                ],
                'capabilities' => [
                    [
                        'name' => 'AdKit read access',
                        'status' => 'deferred',
                    ],
                    [
                        'name' => 'Zernio read access',
                        'status' => 'deferred',
                    ],
                    [
                        'name' => 'Provider actions',
                        'status' => 'disabled',
                    ],
                ],
            ],
        ]);
    }
}
