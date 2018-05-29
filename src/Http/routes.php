<?php
/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your webhooks
|
*/

Route::post(config('deploy.route'), 'Mylgeorge\Deploy\Http\Controllers\GitController')->middleware(config('deploy.middleware'));