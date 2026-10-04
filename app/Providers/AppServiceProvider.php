<?php

namespace App\Providers;

use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Definitions\ForumThreadType;
use App\ContentTypes\Definitions\NewsArticleType;
use App\ContentTypes\Definitions\SchreckNetThreadType;
use App\Services\Export\ContentDocumentRenderer;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
