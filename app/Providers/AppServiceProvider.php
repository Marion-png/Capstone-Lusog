<?php

namespace App\Providers;

use App\Support\RequestMemo;
use App\Support\SchemaCache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not singleton: the schema cannot change while one request is
        // being served, but it certainly can between requests, and a scoped
        // binding is discarded at the end of each request and each queue job.
        $this->app->scoped(SchemaCache::class);
        $this->app->scoped(RequestMemo::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
