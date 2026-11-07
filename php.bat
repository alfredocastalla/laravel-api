@echo off
setlocal

call :find_php
if not defined PHP_EXE (
    echo No PHP 8.3+ CLI with the PDO MySQL extension was found.
    echo Enable pdo_mysql in WAMP or select a compatible PHP version.
    if "%~1"=="" pause
    exit /b 1
)

if not "%~1"=="" (
    "%PHP_EXE%" %*
    exit /b %ERRORLEVEL%
)

set "APP_DIR=%~dp0"
if not exist "%APP_DIR%artisan" (
    echo Laravel application not found: "%APP_DIR%"
    pause
    exit /b 1
)

pushd "%APP_DIR%"
if not exist "vendor\autoload.php" (
    if exist "%~dp0composer.phar" (
        "%PHP_EXE%" "%~dp0composer.phar" install --no-interaction
        if errorlevel 1 (
            popd
            pause
            exit /b 1
        )
    ) else (
        echo Composer dependencies are missing. Install Composer dependencies in "%APP_DIR%".
        popd
        pause
        exit /b 1
    )
)

if not exist "vendor\autoload.php" (
    echo Composer dependencies could not be found after installation.
    popd
    pause
    exit /b 1
)

if not exist ".env" (
    copy ".env.example" ".env" >nul
    if errorlevel 1 (
        echo Could not create .env from .env.example.
        popd
        pause
        exit /b 1
    )
)

findstr /x /c:"APP_KEY=" ".env" >nul
if not errorlevel 1 (
    "%PHP_EXE%" artisan key:generate --force
    if errorlevel 1 (
        popd
        pause
        exit /b 1
    )
)

echo Clearing stale Laravel caches...
"%PHP_EXE%" artisan optimize:clear
if errorlevel 1 (
    echo Laravel could not clear its cached configuration.
    popd
    pause
    exit /b 1
)

"%PHP_EXE%" artisan barangaycare:prepare-database
if errorlevel 1 (
    echo Laravel could not connect to MySQL or create the database. Check that WAMP MySQL is running and DB_* values in "%APP_DIR%.env" are correct.
    popd
    pause
    exit /b 1
)

"%PHP_EXE%" artisan migrate --seed --force
if errorlevel 1 (
    echo Laravel could not prepare its database. Check that WAMP MySQL is running and DB_* values in "%APP_DIR%.env" are correct.
    popd
    pause
    exit /b 1
)

"%PHP_EXE%" artisan barangaycare:bootstrap-admin
if errorlevel 1 (
    echo BarangayCare could not securely prepare the initial Admin account.
    popd
    pause
    exit /b 1
)

echo Starting BarangayCare.
echo On this computer, open http://127.0.0.1:8000
echo On a phone or tablet, connect to the same Wi-Fi, then open one of these addresses:
for /f "tokens=2 delims=:" %%A in ('ipconfig ^| findstr /R /C:"IPv4 Address"') do (
    for /f "tokens=* delims= " %%B in ("%%A") do echo   http://%%B:8000
)
echo Keep this window open while using the app. Press Ctrl+C to stop it.
echo Public internet is not needed while devices are connected to the same local Wi-Fi.
start "" powershell -NoProfile -WindowStyle Hidden -Command "Start-Sleep -Seconds 2; $url='http://127.0.0.1:8000'; $chrome='C:\Program Files\Google\Chrome\Application\chrome.exe'; $edge='${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe'; if (Test-Path $chrome) { Start-Process $chrome $url } elseif (Test-Path $edge) { Start-Process $edge $url } else { Start-Process $url }"
"%PHP_EXE%" artisan serve --host=0.0.0.0 --port=8000
set "EXIT_CODE=%ERRORLEVEL%"
popd
if not "%EXIT_CODE%"=="0" pause
exit /b %EXIT_CODE%

:find_php
set "PHP_EXE="

if exist "C:\wamp64\bin\php\php8.4.15\php.exe" (
    "C:\wamp64\bin\php\php8.4.15\php.exe" -r "exit(PHP_VERSION_ID >= 80300 && extension_loaded('pdo_mysql') ? 0 : 1);"
    if not errorlevel 1 set "PHP_EXE=C:\wamp64\bin\php\php8.4.15\php.exe"
)

for /d %%D in ("C:\wamp64\bin\php\php8.4*") do (
    if not defined PHP_EXE if exist "%%~D\php.exe" (
        "%%~D\php.exe" -r "exit(PHP_VERSION_ID >= 80300 && extension_loaded('pdo_mysql') ? 0 : 1);"
        if not errorlevel 1 set "PHP_EXE=%%~D\php.exe"
    )
)

for /d %%D in ("C:\wamp64\bin\php\php8.3*") do (
    if not defined PHP_EXE if exist "%%~D\php.exe" (
        "%%~D\php.exe" -r "exit(PHP_VERSION_ID >= 80300 && extension_loaded('pdo_mysql') ? 0 : 1);"
        if not errorlevel 1 set "PHP_EXE=%%~D\php.exe"
    )
)

for /f "delims=" %%D in ('dir /b /ad /o-n "C:\wamp64\bin\php\php*" 2^>nul') do (
    if not defined PHP_EXE if exist "C:\wamp64\bin\php\%%D\php.exe" (
        "C:\wamp64\bin\php\%%D\php.exe" -r "exit(PHP_VERSION_ID >= 80300 && extension_loaded('pdo_mysql') ? 0 : 1);"
        if not errorlevel 1 set "PHP_EXE=C:\wamp64\bin\php\%%D\php.exe"
    )
)

if not defined PHP_EXE (
    for /f "delims=" %%P in ('where php 2^>nul') do (
        if not defined PHP_EXE (
            "%%P" -r "exit(PHP_VERSION_ID >= 80300 && extension_loaded('pdo_mysql') ? 0 : 1);"
            if not errorlevel 1 set "PHP_EXE=%%P"
        )
    )
)
exit /b 0
