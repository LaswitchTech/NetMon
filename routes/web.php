<?php

/** @var \App\Core\Router $router */

$router->get('/',        'NetMon\Controllers\HomeController@index',   ['WebAuth']);
$router->get('/devices', 'NetMon\Controllers\DeviceController@index', ['WebAuth']);

// Authentication (public — no middleware)
$router->post('/auth/login',  'AuthController@login');
$router->post('/auth/logout', 'AuthController@logout');
$router->get('/auth/me',      'AuthController@me', ['SessionAuth']);

// Token management (requires active session)
$router->get('/api/tokens',        'TokenController@index',  ['SessionAuth']);
$router->post('/api/tokens',       'TokenController@create', ['SessionAuth']);
$router->delete('/api/tokens/{id}','TokenController@revoke', ['SessionAuth']);
