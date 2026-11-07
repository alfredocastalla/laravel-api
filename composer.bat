@echo off
setlocal

set "COMPOSER_PHAR=%~dp0composer.phar"

if not exist "%COMPOSER_PHAR%" (
    echo Composer PHAR was not found: "%COMPOSER_PHAR%"
    exit /b 1
)

call "%~dp0php.bat" "%COMPOSER_PHAR%" %*
exit /b %ERRORLEVEL%
