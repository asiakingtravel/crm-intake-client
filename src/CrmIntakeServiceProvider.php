<?php

namespace AsiaKingTravel\CrmIntake;

use Illuminate\Support\ServiceProvider;

class CrmIntakeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/crm-intake.php', 'crm-intake');

        $this->app->singleton(IntakeClient::class, function ($app) {
            $config = $app['config']->get('crm-intake', []);

            return new IntakeClient(
                (string) ($config['base_url'] ?? ''),
                (string) ($config['token'] ?? ''),
                (int) ($config['timeout'] ?? 10)
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/crm-intake.php' => $this->app->configPath('crm-intake.php'),
            ], 'crm-intake-config');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [IntakeClient::class];
    }
}
