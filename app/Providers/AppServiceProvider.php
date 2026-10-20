<?php

namespace App\Providers;

use App\Inference\InferenceService;
use App\Inference\LaravelInferenceService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InferenceService::class, LaravelInferenceService::class);
    }

    public function boot(): void {}
}
