<?php

namespace App\Providers;

use App\AdKit\Data\AdKitAccount;
use App\AdKit\Services\AdKitReadService;
use App\AdKit\Support\FakeAdKitProvider;
use App\Inference\InferenceService;
use App\Inference\LaravelInferenceService;
use App\Workspace\Contracts\WorkspaceAnalyst;
use App\Workspace\Services\LaravelAiWorkspaceAnalyst;
use App\Workspace\Services\WorkspaceReadService;
use App\Workspace\Support\FakeWorkspaceAnalyst;
use App\Zernio\Data\ZernioAccount;
use App\Zernio\Services\ZernioReadService;
use App\Zernio\Support\FakeZernioProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InferenceService::class, LaravelInferenceService::class);
        $this->app->singleton(AdKitReadService::class, fn (): AdKitReadService => new AdKitReadService(
            new FakeAdKitProvider([
                'default' => [new AdKitAccount('adkit-demo', 'Demo campaign account', 'connected', now()->toIso8601String())],
            ]),
        ));
        $this->app->singleton(ZernioReadService::class, fn (): ZernioReadService => new ZernioReadService(
            new FakeZernioProvider([
                'default' => [new ZernioAccount('zernio-demo', 'Demo social account', 'linkedin', 'connected', now()->toIso8601String())],
            ]),
        ));
        $this->app->singleton(WorkspaceAnalyst::class, fn (): WorkspaceAnalyst => config('services.workspace.ai_driver') === 'laravel-ai'
            ? new LaravelAiWorkspaceAnalyst
            : new FakeWorkspaceAnalyst);
        $this->app->singleton(WorkspaceReadService::class);
    }

    public function boot(): void {}
}
