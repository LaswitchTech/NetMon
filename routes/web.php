<?php

/** @var \App\Core\Router $router */

$router->get('/',           'NetMon\Controllers\HomeController@index',      ['WebAuth']);
$router->get('/devices',    'NetMon\Controllers\DeviceController@index',   ['WebAuth']);
$router->get('/discovery',  'NetMon\Controllers\DiscoveryController@index', ['WebAuth']);
// NOTE: /discovery/jobs/* must be before /discovery/{id} to prevent "jobs" being captured as an id.
$router->get('/discovery/jobs',              'NetMon\Controllers\DiscoveryController@jobIndex',      ['WebAuth']);
$router->get('/discovery/jobs/create',       'NetMon\Controllers\DiscoveryController@jobCreateForm', ['WebAuth']);
$router->post('/discovery/jobs',             'NetMon\Controllers\DiscoveryController@jobStore',      ['WebAuth']);
$router->get('/discovery/jobs/{id}/edit',    'NetMon\Controllers\DiscoveryController@jobEditForm',   ['WebAuth']);
$router->post('/discovery/jobs/{id}',        'NetMon\Controllers\DiscoveryController@jobUpdate',     ['WebAuth']);
$router->post('/discovery/jobs/{id}/delete', 'NetMon\Controllers\DiscoveryController@jobDelete',     ['WebAuth']);
// NOTE: /discovery/{id}/create-device must be before /discovery/{id} to avoid path ambiguity.
$router->get('/discovery/{id}/create-device',  'NetMon\Controllers\DiscoveryController@createDeviceForm', ['WebAuth']);
$router->post('/discovery/{id}/create-device', 'NetMon\Controllers\DiscoveryController@createDevice',     ['WebAuth']);
// Notes routes must have more segments than /discovery/{id}; listed explicitly for clarity.
$router->post('/discovery/{id}/notes',                       'NetMon\Controllers\DiscoveryController@addNote',    ['WebAuth']);
$router->post('/discovery/{id}/notes/{noteId}/delete',       'NetMon\Controllers\DiscoveryController@deleteNote', ['WebAuth']);
$router->get('/discovery/{id}',                'NetMon\Controllers\DiscoveryController@show',             ['WebAuth']);
$router->post('/discovery/{id}/link',          'NetMon\Controllers\DiscoveryController@link',             ['WebAuth']);
$router->post('/discovery/{id}/ignore',        'NetMon\Controllers\DiscoveryController@ignore',           ['WebAuth']);

$router->get('/alerts',                   'NetMon\Controllers\AlertController@index',       ['WebAuth']);
// NOTE: /alerts/{id} must come after /alerts to avoid empty-string capture.
// NOTE: /alerts/{id}/notes and /alerts/{id}/notes/{noteId}/delete must be before /alerts/{id}
//       to ensure the literal "notes" segment is not captured as a separate {id} param
//       on routes defined later. In practice [^/]+ doesn't swallow slashes, so multi-segment
//       routes never conflict, but ordering by specificity is kept for clarity.
$router->post('/alerts/{id}/notes',                       'NetMon\Controllers\AlertController@addNote',    ['WebAuth']);
$router->post('/alerts/{id}/notes/{noteId}/delete',       'NetMon\Controllers\AlertController@deleteNote', ['WebAuth']);
$router->get('/alerts/{id}',              'NetMon\Controllers\AlertController@show',        ['WebAuth']);
$router->post('/alerts/{id}/acknowledge', 'NetMon\Controllers\AlertController@acknowledge', ['WebAuth']);
$router->post('/alerts/{id}/suppress',    'NetMon\Controllers\AlertController@suppress',    ['WebAuth']);

// Device CRUD — browser routes (POST used for update/delete; HTML forms do not support PUT/DELETE)
// NOTE: /devices/create must be registered before /devices/{id} to avoid "create" being captured as an id.
// NOTE: /devices/interfaces/{id} and /devices/addresses/{id} must be before /devices/{id} so the
//       literal "interfaces"/"addresses" segments are not swallowed by the {id} wildcard.
$router->get('/devices/create',                         'NetMon\Controllers\DeviceController@createForm',           ['WebAuth']);
$router->post('/devices',                               'NetMon\Controllers\DeviceController@store',                ['WebAuth']);
// Interface CRUD (literal "interfaces" segment must precede /devices/{id})
$router->get('/devices/{id}/interfaces/create',  'NetMon\Controllers\DeviceController@interfaceCreateForm', ['WebAuth']);
$router->post('/devices/{id}/interfaces',        'NetMon\Controllers\DeviceController@interfaceStore',      ['WebAuth']);
$router->get('/devices/interfaces/{id}/edit',    'NetMon\Controllers\DeviceController@interfaceEditForm',   ['WebAuth']);
$router->post('/devices/interfaces/{id}',        'NetMon\Controllers\DeviceController@interfaceUpdate',     ['WebAuth']);
$router->post('/devices/interfaces/{id}/delete', 'NetMon\Controllers\DeviceController@interfaceDelete',     ['WebAuth']);

// Address CRUD (literal "addresses" segment must precede /devices/{id})
$router->get('/devices/interfaces/{id}/addresses/create', 'NetMon\Controllers\DeviceController@addressCreateForm', ['WebAuth']);
$router->post('/devices/interfaces/{id}/addresses',       'NetMon\Controllers\DeviceController@addressStore',      ['WebAuth']);
$router->get('/devices/addresses/{id}/edit',              'NetMon\Controllers\DeviceController@addressEditForm',   ['WebAuth']);
$router->post('/devices/addresses/{id}',                  'NetMon\Controllers\DeviceController@addressUpdate',     ['WebAuth']);
$router->post('/devices/addresses/{id}/delete',           'NetMon\Controllers\DeviceController@addressDelete',     ['WebAuth']);

// Monitored Service CRUD (literal "services" segment must precede /devices/{id})
$router->get('/devices/{id}/services/create',  'NetMon\Controllers\DeviceController@serviceCreateForm', ['WebAuth']);
$router->post('/devices/{id}/services',        'NetMon\Controllers\DeviceController@serviceStore',      ['WebAuth']);
$router->get('/devices/services/{id}/edit',    'NetMon\Controllers\DeviceController@serviceEditForm',   ['WebAuth']);
$router->post('/devices/services/{id}',        'NetMon\Controllers\DeviceController@serviceUpdate',     ['WebAuth']);
$router->post('/devices/services/{id}/delete', 'NetMon\Controllers\DeviceController@serviceDelete',     ['WebAuth']);

// Notes (device) — both routes have more segments than /devices/{id} so no wildcard conflict
$router->post('/devices/{id}/notes',                       'NetMon\Controllers\DeviceController@addNote',    ['WebAuth']);
$router->post('/devices/{id}/notes/{noteId}/delete',       'NetMon\Controllers\DeviceController@deleteNote', ['WebAuth']);

$router->get('/devices/{id}',             'NetMon\Controllers\DeviceController@show',       ['WebAuth']);
$router->get('/devices/{id}/edit',        'NetMon\Controllers\DeviceController@editForm',   ['WebAuth']);
$router->post('/devices/{id}',            'NetMon\Controllers\DeviceController@update',     ['WebAuth']);
$router->post('/devices/{id}/delete',     'NetMon\Controllers\DeviceController@delete',     ['WebAuth']);
$router->get('/devices/{id}/merge',       'NetMon\Controllers\DeviceController@mergeForm',  ['WebAuth']);
$router->post('/devices/{id}/merge',      'NetMon\Controllers\DeviceController@merge',      ['WebAuth']);

// Admin area — requires 'admin' permission on all routes.
// Handler prefix 'Controllers\...' resolves to App\Controllers\... via the Router's qualified-name rule.
$router->get('/admin',             'Controllers\Admin\AdminController@index',       ['WebAuth', 'WebPermission:admin']);
$router->get('/admin/permissions', 'Controllers\Admin\AdminController@permissions', ['WebAuth', 'WebPermission:admin']);
$router->get('/admin/audit',       'Controllers\Admin\AdminController@audit',       ['WebAuth', 'WebPermission:admin']);

// Admin users — full CRUD + group assignment.
// NOTE: /admin/users/create must be before /admin/users/{id}/edit to prevent "create" being captured as an id.
// NOTE: /admin/users/{id}/edit-account and /admin/users/{id}/account must be before the plain {id} catch-all.
$router->get('/admin/users',                   'Controllers\Admin\UserController@index',          ['WebAuth', 'WebPermission:admin']);
$router->get('/admin/users/create',            'Controllers\Admin\UserController@createForm',     ['WebAuth', 'WebPermission:admin']);
$router->post('/admin/users',                  'Controllers\Admin\UserController@store',          ['WebAuth', 'WebPermission:admin']);
$router->get('/admin/users/{id}/edit-account', 'Controllers\Admin\UserController@editAccountForm',['WebAuth', 'WebPermission:admin']);
$router->post('/admin/users/{id}/account',     'Controllers\Admin\UserController@updateAccount',  ['WebAuth', 'WebPermission:admin']);
$router->post('/admin/users/{id}/activate',    'Controllers\Admin\UserController@activate',       ['WebAuth', 'WebPermission:admin']);
$router->post('/admin/users/{id}/deactivate',  'Controllers\Admin\UserController@deactivate',     ['WebAuth', 'WebPermission:admin']);
$router->get('/admin/users/{id}/edit',         'Controllers\Admin\UserController@editForm',       ['WebAuth', 'WebPermission:admin']);
$router->post('/admin/users/{id}',             'Controllers\Admin\UserController@update',         ['WebAuth', 'WebPermission:admin']);

// Admin groups — CRUD routes.
// NOTE: /admin/groups/create must be registered before /admin/groups/{id} and
//       /admin/groups/{id}/edit must be before any plain {id} catch-all, so the
//       literal "create" segment is never captured as an id.
$router->get('/admin/groups',              'Controllers\Admin\GroupController@index',      ['WebAuth', 'WebPermission:admin']);
$router->get('/admin/groups/create',       'Controllers\Admin\GroupController@createForm', ['WebAuth', 'WebPermission:admin']);
$router->post('/admin/groups',             'Controllers\Admin\GroupController@store',      ['WebAuth', 'WebPermission:admin']);
$router->get('/admin/groups/{id}/edit',    'Controllers\Admin\GroupController@editForm',   ['WebAuth', 'WebPermission:admin']);
$router->post('/admin/groups/{id}',        'Controllers\Admin\GroupController@update',     ['WebAuth', 'WebPermission:admin']);
$router->post('/admin/groups/{id}/delete', 'Controllers\Admin\GroupController@delete',     ['WebAuth', 'WebPermission:admin']);

// File Manager — reusable module, gated by files.manage permission.
// NOTE: static sub-paths (mkdir, upload, download, delete) must be registered
//       before /files/{rootId} so the literal segments are not captured as {rootId}.
$router->get('/files',                    'Modules\FileManager\Controllers\FileManagerController@index',    ['WebAuth', 'WebPermission:files.manage']);
$router->get('/files/{rootId}/download',  'Modules\FileManager\Controllers\FileManagerController@download', ['WebAuth', 'WebPermission:files.manage']);
$router->post('/files/{rootId}/mkdir',    'Modules\FileManager\Controllers\FileManagerController@mkdir',    ['WebAuth', 'WebPermission:files.manage']);
$router->post('/files/{rootId}/upload',   'Modules\FileManager\Controllers\FileManagerController@upload',   ['WebAuth', 'WebPermission:files.manage']);
$router->post('/files/{rootId}/delete',   'Modules\FileManager\Controllers\FileManagerController@delete',   ['WebAuth', 'WebPermission:files.manage']);
$router->get('/files/{rootId}',           'Modules\FileManager\Controllers\FileManagerController@browse',   ['WebAuth', 'WebPermission:files.manage']);

// Profile (user-owned settings: account summary, notification prefs, API tokens)
$router->get('/profile',                            'ProfileController@index',                       ['WebAuth']);
$router->post('/profile/notification-preferences',  'ProfileController@saveNotificationPreferences', ['WebAuth']);

// Notifications — full inbox page + read actions
// NOTE: /notifications/read-all must be before /notifications/{id}/read to
//       prevent the literal "read-all" from being captured as an {id} param.
// markRead() and markAllRead() support both redirect (form POST) and JSON
// (AJAX with Accept: application/json) response modes.
$router->get('/notifications',                   'Modules\Notifications\Controllers\NotificationController@index',       ['WebAuth']);
$router->post('/notifications/read-all',         'Modules\Notifications\Controllers\NotificationController@markAllRead', ['WebAuth']);
$router->post('/notifications/{id}/read',        'Modules\Notifications\Controllers\NotificationController@markRead',    ['WebAuth']);
// JSON endpoints — SessionAuth returns 401 JSON on failure (not redirect),
// matching the AJAX fetch patterns used in the topbar dropdown.
$router->get('/api/notifications/count',         'Modules\Notifications\Controllers\NotificationController@unreadCount', ['SessionAuth']);
$router->get('/api/notifications/recent',        'Modules\Notifications\Controllers\NotificationController@recent',      ['SessionAuth']);

// Authentication (public — no middleware)
$router->get('/auth/login',   'AuthController@loginForm');
$router->post('/auth/login',  'AuthController@login');
$router->post('/auth/logout', 'AuthController@logout');
$router->get('/auth/me',      'AuthController@me', ['SessionAuth']);

// Token management (requires active session — used by Profile page JS)
$router->get('/api/tokens',        'TokenController@index',  ['SessionAuth']);
$router->post('/api/tokens',       'TokenController@create', ['SessionAuth']);
$router->delete('/api/tokens/{id}','TokenController@revoke', ['SessionAuth']);
