<?php

/** @var \App\Core\Router $router */

$router->get('/',        'NetMon\Controllers\HomeController@index',   ['WebAuth']);
$router->get('/devices', 'NetMon\Controllers\DeviceController@index', ['WebAuth']);
$router->get('/alerts',                   'NetMon\Controllers\AlertController@index',       ['WebAuth']);
// NOTE: /alerts/{id} must come after /alerts to avoid empty-string capture.
$router->get('/alerts/{id}',              'NetMon\Controllers\AlertController@show',        ['WebAuth']);
$router->post('/alerts/{id}/acknowledge', 'NetMon\Controllers\AlertController@acknowledge', ['WebAuth']);
$router->post('/alerts/{id}/suppress',    'NetMon\Controllers\AlertController@suppress',    ['WebAuth']);

// Device CRUD — browser routes (POST used for update/delete; HTML forms do not support PUT/DELETE)
// NOTE: /devices/create must be registered before /devices/{id} to avoid "create" being captured as an id.
$router->get('/devices/create',           'NetMon\Controllers\DeviceController@createForm', ['WebAuth']);
$router->post('/devices',                 'NetMon\Controllers\DeviceController@store',      ['WebAuth']);
$router->get('/devices/{id}',             'NetMon\Controllers\DeviceController@show',       ['WebAuth']);
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
