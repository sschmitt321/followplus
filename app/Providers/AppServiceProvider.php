<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configure Scramble API documentation
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                // Add JWT Bearer Token authentication scheme
                // This will add a global "Authorize" button in the documentation UI
                $openApi->secure(
                    SecurityScheme::http('bearer', 'JWT')
                        ->as('bearerAuth')
                        ->setDescription('Enter your JWT token. Use the token obtained from /api/v1/auth/login or /api/v1/auth/register endpoint.')
                        ->default()
                );
            });
    }
}
