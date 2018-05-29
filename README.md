# Deployments Laravel projects using Git webhooks

Refactored code of 'orphans/git-deploy-laravel' and use of symfony/process

### Installation

```
composer require mylgeorge/git-deploy-laravel
```

### Publish the config

```
php artisan vendor:publish --provider="Mylgeorge\Deploy\Providers\GitServiceProvider" --tag="config"
```

### Future Plans

* fire events
* send email
* support for post pull process 
