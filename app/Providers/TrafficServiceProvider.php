<?php

namespace App\Providers;

use App\Application\Traffic\EffectDispatcher;
use App\Application\Traffic\OutboxEffectDispatcher;
use App\Application\Traffic\SensorEventHandler;
use App\Application\Traffic\TrafficService;
use App\Domain\Traffic\ControllerPort;
use App\Infrastructure\Traffic\RestSimulatorController;
use Illuminate\Support\ServiceProvider;

class TrafficServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ControllerPort::class, function ($app) {
            $service = $app->make(TrafficService::class);

            return new RestSimulatorController($service, (bool) env('TRAFFIC_AUTO_ACK', false));
        });
        if (! $this->app->bound(EffectDispatcher::class)) {
            $this->app->bind(EffectDispatcher::class, OutboxEffectDispatcher::class);
        }
        if (! $this->app->bound(SensorEventHandler::class)) {
            $this->app->bind(SensorEventHandler::class, SensorEventHandler::class);
        }
    }

    public function boot(): void
    {
        //
    }
}
