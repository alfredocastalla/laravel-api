# BarangayCare

BarangayCare is a client-server prototype for a Digital Beneficiary Management and Social Assistance Monitoring with Scheduling System for Barangay Taft, Surigao City.

## Run the Laravel app on Windows

1. Start WAMP's MySQL service.
2. Double-click `php.bat` in this folder. It finds WAMP's PHP 8.4.1+ executable, prepares Laravel's `.env` and application key when needed, creates the configured database if needed, runs Laravel migrations and seeders, then starts `php artisan serve`.
3. Open `http://127.0.0.1:8000` on the server computer. Keep the command window open while using the system.
4. To use phones or other computers without public internet, connect them to the same Wi-Fi as the server computer and open `http://<server-computer-LAN-IP>:8000`. The launcher prints the available LAN addresses. Do not use `127.0.0.1` on another device; that address points back to that device. If Windows Firewall prompts you, allow access on private networks only.

`php.bat` selects WAMP PHP 8.4.1 or newer. Double-click it to prepare the database and start the server. `php.bat artisan ...` still runs Artisan with that same PHP, and `composer.bat` uses `composer.phar` in this folder.

Laravel is the system's only active backend: it serves the interface, provides the API, creates the schema with migrations, and stores records in MySQL. If you already have a Laravel `.env` file, the launcher leaves it unchanged; check its `DB_CONNECTION`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` values if setup cannot connect.

Screen navigation uses Laravel URLs: Resident, Barangay, and Admin sign-in paths include `/login/resident`, `/login/barangay`, and `/login/admin`; sidebar pages use `/app/resident/...`, `/app/barangay/...`, and `/app/admin/...`. These links can be refreshed or shared within the running app, and browser back/forward restores the selected screen. The browser keeps the current account's display fields and bearer token in tab-scoped storage; Laravel validates the token and enforces the account role on API requests.

## Web and Mobile App

The same responsive Laravel web app can be installed as a Progressive Web App (PWA) on supported phones and computers. Open BarangayCare in the browser, choose **Install BarangayCare** when offered, or use the browser's **Install app** / **Add to Home Screen** menu. On iPhone or iPad, open the site in Safari, tap **Share**, then **Add to Home Screen**.

The app shell can reopen offline after it has been loaded once, but signing in, loading records, and saving changes require a connection to the Laravel server. At the barangay hall, a local Wi-Fi connection to the server is enough; public internet is not required. The server computer and its MySQL service must stay on. Email and password-reset delivery still need the configured mail service. Production hosting must use HTTPS for service workers and installation to work.

## Project Structure

- `app/Http/Controllers/BarangayCareApiController.php` handles API requests, validation, authorization checks, and notifications. Its `//#region` sections separate endpoint groups.
- `app/Http/Controllers/BarangayCareFrontendController.php` serves the application page, stylesheet, and JavaScript fallback.
- `app/Http/Middleware/AuthenticateBarangayCare.php` validates the bearer token for protected API requests.
- `app/Services/BarangayCareAuth.php` handles account authentication and tokens; `app/Services/BarangayCareStore.php` reads and writes system records. The store's `//#region` sections separate data responsibilities.
- `app/Support/AccountRole.php` and `app/Support/BenefitCategory.php` contain shared role and benefit-category rules.
- `routes/web.php` maps page URLs; `routes/api.php` maps API endpoints to the API controller.
- `resources/views/app.blade.php` is the page shell. `resources/js/app.js` contains the UI, with `//#region` sections for navigation in VS Code. `resources/css/app.css` contains application styles.
- `public/laravel-service.js` connects the browser UI to the Laravel API. `vite.config.js` and `package.json` configure frontend builds.
- `database/migrations/` defines the MySQL schema; `database/seeders/` provides initial records.
- `tests/Feature/BarangayCareApiTest.php` covers API behavior, roles, and privacy; other feature tests cover setup and commands.
- `php.bat` starts Laravel using the PHP version installed with WAMP. `artisan` is Laravel's command runner.

## Finding and Debugging a Feature

1. Find the page URL and role mapping in `routes/web.php`, then find the page component in `resources/js/app.js` under its matching `//#region`.
2. Find the browser request in `public/laravel-service.js`, then match its endpoint in `routes/api.php`.
3. Follow the endpoint into `BarangayCareApiController.php`; database reads and writes generally continue into `BarangayCareStore.php`.
4. Check `tests/Feature/BarangayCareApiTest.php` for existing behavior coverage before changing an API feature.

Useful commands from this folder:

```powershell
php.bat artisan route:list --path=api
php.bat artisan test --compact
npm run build
```

## Offline Use

The browser can cache the interface for offline access, but saving or loading system records requires the Laravel backend and its database to be running.

For best offline behavior:

1. Start WAMP MySQL and run `php.bat`.
2. Open `http://127.0.0.1:8000`.
3. Wait for the page to finish loading.
4. The cached interface can reopen offline, but API actions will be unavailable until Laravel is running again.

## Assistance Email Notifications

Configure these values in `.env` to send resident notifications through Gmail SMTP:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_USERNAME=your-gmail-address
MAIL_PASSWORD=your-gmail-app-password
MAIL_FROM_ADDRESS="${MAIL_USERNAME}"
MAIL_FROM_NAME="${APP_NAME}"
```

Use a Gmail App Password, not your normal account password. Check the mail configuration at `http://127.0.0.1:8000/api/mail-status`. Laravel reads `.env` when it starts, so restart the server after changing these values.

Public account creation is disabled. Database seeding does not create shared Admin or Barangay accounts outside the test environment. On a new local installation, `php.bat` securely prompts for an Admin name, username, and private password when no Admin exists; the password is not echoed or stored in source code. Admin can then create the Barangay Captain account, and the Captain can create resident and scoped staff accounts. Existing accounts and credentials are left unchanged. The legacy shared demo resident account is deactivated when its default credentials are still in place. Admin access is monitoring-only.

## Included Modules

- Role-based login interfaces for residents, Barangay users, and administrators
- User dashboard for assistance records, schedules, reports, profile, and settings
- Admin dashboard for user management, modules, audit logs, backup, activity monitoring, and system settings
- Barangay workspace for resident, assistance, and program management
- Beneficiary registration and real-time record updates
- Beneficiary editing and status management
- Assistance recording and tracking
- Assistance claim confirmation and claimed-date tracking
- Program scheduling
- Program editing and completion status management
- User account creation, editing, activation, and deactivation
- Search and filtering
- Printable reports
- Database-backed backup download through the Laravel API
- Laravel-backed assistant for assistance questions and release-message logging
- Browser-based audit activity log stored through the Laravel API

## Requested Feature Checklist

1. Beneficiary registration and profile management: implemented.
2. Social assistance monitoring and tracking: implemented.
3. Assistance program and barangay activity scheduling: implemented.
4. User authentication and role-based access control: implemented for Admin, Barangay, Resident, Secretary, Assistance Officer, Distribution Staff, and Read-only Auditor roles.
5. Report generation and printing: implemented.
6. Audit logs: implemented and stored in MySQL.
7. Real-time dashboard summaries: implemented from the active backend data.
8. Beneficiary search and filtering: implemented.
9. Web admin panel and responsive mobile layout: implemented.
10. Laravel/MySQL storage: the Laravel API and MySQL database are the active persistence backend.

The Laravel-served application uses Laravel's API and database.

## How to Run with WAMP

1. Copy the whole project folder into `C:\wamp64\www\barangaycare`.
2. Start WAMP's MySQL service. Laravel creates `barangaycare_db` when the launcher runs.
3. Run `php.bat` in this folder.
4. Open `http://127.0.0.1:8000`.
5. Check the Laravel API at `http://127.0.0.1:8000/api/health`.

There are no shared default login credentials. To create the initial Admin on a local WAMP installation, run `php.bat` and enter a unique username and private password when prompted. For other deployment methods, run `php artisan barangaycare:bootstrap-admin` from an interactive server console after the database migrations. Tests create isolated fixture accounts only in the testing environment.

Barangay accounts can manage resident, assistance, and schedule records from the existing Barangay workspace. The Barangay Captain creates resident and scoped staff accounts; Admin accounts have monitoring-only access. Server-side API rules enforce each role's data and write boundaries.

## Technology Implementation

BarangayCare is structured as a web-based and mobile-accessible client-server application. The front end uses HTML, CSS, JavaScript, and ReactJS through browser CDN scripts. The interface is responsive, role-based, and designed for non-technical barangay staff and beneficiaries.

Laravel routes in `routes/web.php` serve the frontend, while `routes/api.php` handles authentication, data access, health checks, mail status, notifications, assistant answers, and database-backed backups. Mutating actions save to MySQL through Laravel before the interface reports success.

The browser uses the clean Laravel API paths below. Older `.php` API paths remain available as compatibility aliases.

| Purpose | Laravel API route |
| --- | --- |
| Database connection | `GET /api/health` |
| Read/write application data | `GET /api/data`, `POST /api/data` |
| Sign in | `POST /api/auth` |
| Check data version | `GET /api/version` |
| Send assistance notification | `POST /api/notify-assistance` |
| Check SMTP configuration | `GET /api/mail-status` |
| Export database backup | `GET /api/backup` |
| Ask the assistance helper | `POST /api/chat` |

Use `php.bat artisan route:list --path=api` to inspect registered API endpoints. Double-click `php.bat` to select PHP, prepare the database, and start the Laravel server.

MySQL stores each application area in its own table:

- `beneficiaries` stores resident records.
- `programs` stores schedules.
- `assistance` stores assistance and claim records.
- `users` stores login accounts and profile fields.
- `logs` stores audit activity.
- `settings` stores system settings.
- `app_state` is retained as a compatibility copy.

Laravel migrations create and upgrade the tables. The Laravel seeder loads the demo records for a new database and can import data from an existing `app_state` compatibility row.

## Capstone Presentation Highlights

- ReactJS front end for dynamic dashboard rendering and module navigation
- Laravel/PHP backend using MySQL
- Role-based access for Admin, Barangay, and Resident accounts
- Beneficiary management with duplicate checking
- Assistance monitoring with status tracking
- Claim confirmation workflow for received assistance
- Scheduling module for assistance programs
- Account-to-beneficiary privacy linking
- AI message assistant for release schedule notices
- Printable reports and backup export
- Audit logs for accountability

The interface is served from `public`; page routes are registered in `routes/web.php`, while application APIs are registered in `routes/api.php` and implemented by Laravel controllers.

## Security and Access Control

The application separates access by role: Admin accounts are monitoring-only, while Barangay accounts retain their operational privileges; Secretary and Assistance Officer writes are restricted to their assigned records; Distribution Staff can only record claims; Read-only Auditor cannot modify records. Residents can view records linked to their own accounts. These boundaries are enforced by the Laravel API, not only by hidden navigation links.
