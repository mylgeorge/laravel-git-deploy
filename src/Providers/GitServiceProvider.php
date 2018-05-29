<?php
namespace Mylgeorge\Deploy\Providers;

use Illuminate\Support\ServiceProvider;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Mylgeorge\Deploy\Contracts\Deploy;
use Mylgeorge\Deploy\GitDeploy;

class GitServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadRoutesFrom(__dir__ . '/../Http/routes.php');
    }


    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {

        $this->registerConfig();

        $this->app->bind(Deploy::class , function ($app){

            $logger = new Logger(GitDeploy::class);
            $logger->pushHandler(new StreamHandler(storage_path('logs/deploy.log'), Logger::WARNING));

            return new GitDeploy(
                $logger
            );
        });
    }

    protected function registerConfig()
    {
        if($this->app->runningInConsole()) {
            $this->publishes([
                realpath(__DIR__ . '/../../config/deploy.php') => config_path('deploy.php')
            ], 'config');
        }

        $this->mergeConfigFrom(realpath(__DIR__.'/../../config/deploy.php'), 'deploy');
    }
}