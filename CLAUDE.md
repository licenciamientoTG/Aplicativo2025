# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## About This Application

TotalGas is a custom PHP MVC web application for managing fuel/gas station operations across 40+ locations. It handles supply chain, accounting, sales, HR, operations, and administrative functions with multi-database support (one central DB + one per station).

## Running the Application

**Local development (PHP built-in server):**
```bash
php -S localhost:8000 router.php
```

**Production:** Hosted on IIS — URL rewriting is handled by `web.config` (rewrites all requests to `index.php?url={R:1}`).

**Install dependencies:**
```bash
composer install
```

No build step, no npm, no test framework exists in this project.

## Architecture

### Request Flow

```
Browser → index.php (front controller)
  → Reads ?url param
  → Loads _assets/classes/header.class.php (constants, DB config)
  → Checks session auth
  → Instantiates controller class from _assets/controllers/
  → Calls method on controller
  → Controller uses models from _assets/models/
  → Renders Twig template from views/
```

URL pattern: `/[controller]/[method]/[optional-params]`
Example: `/supply/add_payment` → `Supply::add_payment()`

### Key Files

| File | Purpose |
|------|---------|
| `index.php` | Front controller — routing, auth check, Twig init |
| `router.php` | Routes static assets vs dynamic requests for PHP built-in server |
| `_assets/classes/header.class.php` | App constants, DB credentials, base URL, timezone |
| `_assets/classes/common/MySqlPdoHandler.class.php` | Singleton DB connection — `::getInstance()` |
| `_assets/models/Model.php` | Base model — all domain models extend this |
| `_assets/classes/twig_functions.php` | Custom Twig extension functions |
| `_assets/classes/php_functions.php` | Global utility functions (included everywhere) |
| `_assets/includes/validate.inc.php` | Login POST handler, session creation |

### Database

- **Primary DB:** `TG` on `192.168.0.6` — central application data
- **Secondary DB:** `SG12` on same host — station regional data
- **Station DBs:** Each of the 40+ gas stations has its own SQL Server instance (IP range `192.168.2.101`–`192.168.40.101`) accessed via linked servers
- **Driver:** PDO with `sqlsrv` driver (`TrustServerCertificate=yes`)
- **Connection:** `MySqlPdoHandler::getInstance()` returns the Singleton PDO wrapper

**Model query methods:**
```php
$this->db->select($query, $params)
$this->db->insert($query, $params)
$this->db->update($query, $params)
$this->db->delete($query, $params)
$this->db->executeStoredProcedure($name, $params)
$this->db->beginTransaction() / commit() / rollBack()
```

### MVC Conventions

- **Controllers** live in `_assets/controllers/` — one file per business domain (e.g., `supply.php`, `accounting.php`)
- **Models** live in `_assets/models/` — 82 domain-specific models, named `[Domain]Model.php`
- **Views** live in `views/[controller-name]/[method-name].html` — Twig templates
- Controller constructors receive a `$twig` instance and instantiate any needed models
- Controllers render views via `$this->twig->render('module/template.html', $data)`

### Authentication & Authorization

- Session-based: login calls stored procedure `sp_usuario_login`
- User permissions stored in `$_SESSION['tg_user']['permissions']` as a comma-separated string of permission IDs (checked via `authorized($id)`, which does `in_array($id, explode(",", ...))` — see `_assets/classes/php_functions.php:359-361`)
- `index.php` checks session before dispatching to controllers
- Logout handled by `_assets/includes/logout.inc.php`

### Composer Dependencies

- `twig/twig ^3.0` — templating
- `phpmailer/phpmailer ^6.9.1` — email via Gmail SMTP
- `phpoffice/phpspreadsheet ^2.2` — Excel export/import

### Frontend

- Bootstrap Material Design + jQuery (bundled in `_assets/js/`)
- FontAwesome icons (`_assets/css/fontawesome-free/`)
- Controller-specific JS files mirror the controller name (e.g., `_assets/js/supply.js`)
- No JS bundler or transpiler — files are served directly

## Adding New Features

1. Create/update model in `_assets/models/` extending `Model`
2. Add method to the relevant controller in `_assets/controllers/`
3. Create Twig template in `views/[controller]/[method].html`
4. If new controller needed, add routing case in `index.php`
