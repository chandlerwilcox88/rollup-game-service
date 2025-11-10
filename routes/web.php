<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

// Home route
$router->get('/', function () use ($router) {
    return response()->json([
        'service' => 'Roll Up Game Service',
        'version' => '1.0.0',
        'docs' => '/api/health',
    ]);
});

// API Routes
$router->group(['prefix' => 'api'], function () use ($router) {
    // Health check
    $router->get('/health', 'GameController@health');

    // Game management
    $router->post('/games', 'GameController@store');
    $router->get('/games/{gameId}', 'GameController@show');
    $router->post('/games/{gameId}/action', 'GameController@action');
    $router->get('/games/{gameId}/results', 'GameController@results');
    $router->post('/games/{gameId}/end', 'GameController@end');

    // Provably fair endpoints
    $router->get('/games/{gameId}/server-seed-hash', 'GameController@serverSeedHash');
    $router->get('/games/{gameId}/server-seed', 'GameController@serverSeed');
    $router->post('/games/{gameId}/verify', 'GameController@verify');
    $router->post('/games/{gameId}/update-seed', 'GameController@updateSeed');
});
