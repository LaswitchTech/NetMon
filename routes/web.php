<?php

/** @var \App\Core\Router $router */

$router->get('/',        'NetMon\Controllers\HomeController@index',   ['WebAuth']);
$router->get('/devices', 'NetMon\Controllers\DeviceController@index', ['WebAuth']);
$router->get('/alerts',  'NetMon\Controllers\AlertController@index',  ['WebAuth']);

// Device CRUD — browser routes (POST used for update/delete; HTML forms do not support PUT/DELETE)
$router->get('/devices/create',           'NetMon\Controllers\DeviceController@createForm', ['WebAuth']);
$router->post('/devices',                 'NetMon\Controllers\DeviceController@store',      ['WebAuth']);
$router->get('/devices/{id}/edit',        'NetMon\Controllers\DeviceController@editForm',   ['WebAuth']);
$router->post('/devices/{id}',            'NetMon\Controllers\DeviceController@update',     ['WebAuth']);
$router->post('/devices/{id}/delete',     'NetMon\Controllers\DeviceController@delete',     ['WebAuth']);

// Authentication (public — no middleware)
$router->get('/auth/login',   'AuthController@loginForm');
$router->post('/auth/login',  'AuthController@login');
$router->post('/auth/logout', 'AuthController@logout');
$router->get('/auth/me',      'AuthController@me', ['SessionAuth']);

// Token management (requires active session)
$router->get('/api/tokens',        'TokenController@index',  ['SessionAuth']);
$router->post('/api/tokens',       'TokenController@create', ['SessionAuth']);
$router->delete('/api/tokens/{id}','TokenController@revoke', ['SessionAuth']);
