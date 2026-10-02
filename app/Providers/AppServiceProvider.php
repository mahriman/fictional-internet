<?php

namespace App\Providers;

use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Definitions\NewsArticleType;
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
            fn (): ContentTypeRegistry => new ContentTypeRegistry(new NewsArticleType),
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
