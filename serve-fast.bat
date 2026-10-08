@echo off
rem Farah dev server with OPcache (5-6x faster than "php artisan serve", which runs without OPcache here).
rem Usage: serve-fast.bat [port]   (default 8020)
set PORT=%1
if "%PORT%"=="" set PORT=8020
cd /d "%~dp0public"
echo Farah on http://127.0.0.1:%PORT%  (admin: /admin, providers: /provider, API: /api/v1)
php -d zend_extension=opcache -d opcache.enable_cli=1 -d opcache.memory_consumption=256 -d opcache.max_accelerated_files=30000 -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=0 -d realpath_cache_size=4096K -d realpath_cache_ttl=600 -S 127.0.0.1:%PORT% ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
