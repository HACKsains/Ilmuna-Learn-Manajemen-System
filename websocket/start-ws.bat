@echo off
title Ilmuna Margo One SSO WebSocket Server
cd /d "%~dp0\.."
C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe -d extension_dir="C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\ext" -d extension=pdo_mysql -d extension=openssl -d extension=sockets websocket\sso-realtime-server.php
pause
