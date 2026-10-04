<?php

namespace App\Providers;

use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Definitions\ForumThreadType;
use App\ContentTypes\Definitions\NewsArticleType;
use App\ContentTypes\Definitions\SchreckNetThreadType;
use App\Services\Export\ContentDocumentRenderer;
use App\Services\Export\ExportRenderLimiter;
use App\Services\Export\FirefoxWebDriverBiDiRenderer;
use App\Services\OpenAI\OpenAiClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            ContentTypeRegistry::class,
            fn (): ContentTypeRegistry => new ContentTypeRegistry(new NewsArticleType, new ForumThreadType, new SchreckNetThreadType),
        );

        $this->app->singleton(OpenAiClient::class);
        $this->app->singleton(ContentDocumentRenderer::class, FirefoxWebDriverBiDiRenderer::class);
        $this->app->singleton(
            ExportRenderLimiter::class,
            fn (): ExportRenderLimiter => new ExportRenderLimiter(
                config('exports.render_concurrency', 2),
                (string) config('exports.render_lock_directory', storage_path('framework/locks/export-rendering')),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
