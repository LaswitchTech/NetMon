#!/bin/bash

echo "Running basic checks..."

php -l public/index.php           || exit 1
php -l app/Core/Migration.php     || exit 1
php -l app/Core/MigrationRunner.php || exit 1
php -l scripts/migrate.php        || exit 1
php -l database/migrations/0001_create_migrations_table.php || exit 1
php -l database/migrations/0002_create_users_table.php       || exit 1
php -l database/migrations/0003_create_groups_table.php      || exit 1
php -l database/migrations/0004_create_permissions_table.php || exit 1
php -l database/migrations/0005_create_user_groups_table.php || exit 1
php -l database/migrations/0006_create_group_permissions_table.php || exit 1
php -l database/migrations/0007_create_api_tokens_table.php  || exit 1
php -l database/migrations/0008_add_display_name_to_users.php || exit 1
php -l database/seeds/AdminBootstrap.php                     || exit 1
php -l scripts/seed.php                                      || exit 1
php -l scripts/install.php                                   || exit 1
php -l app/Core/AuthProviderInterface.php                    || exit 1
php -l app/Models/UserRepository.php                         || exit 1
php -l app/Auth/LocalAuthProvider.php                        || exit 1
php -l app/Auth/AuthService.php                              || exit 1
php -l app/Controllers/AuthController.php                    || exit 1
php -l app/Core/MiddlewareInterface.php                      || exit 1
php -l app/Core/Gate.php                                     || exit 1
php -l app/Models/TokenRepository.php                        || exit 1
php -l app/Auth/TokenService.php                             || exit 1
php -l app/Middleware/SessionAuth.php                        || exit 1
php -l app/Middleware/TokenAuth.php                          || exit 1
php -l app/Middleware/RequirePermission.php                  || exit 1
php -l app/Controllers/TokenController.php                   || exit 1
php -l app/NetMon/Controllers/HomeController.php             || exit 1
php -l app/Core/Env.php                                      || exit 1
php -l app/Core/Config.php                                   || exit 1
php -l app/Core/Installer/InstallLock.php                    || exit 1
php -l app/Core/Installer/EnvironmentChecker.php             || exit 1
php -l app/Core/Installer/DirectoryChecker.php               || exit 1
php -l app/Core/Installer/ConfigWriter.php                   || exit 1
php -l app/Modules/Setup/Services/SetupService.php           || exit 1
php -l app/Modules/Setup/Controllers/SetupController.php     || exit 1
php -l config/app.php                                        || exit 1
php -l config/local.php.example                              || exit 1

echo "OK"
