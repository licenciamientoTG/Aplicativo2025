# Backend Instructions

This is a custom PHP MVC application, not Laravel. Controllers are in
`_assets/controllers/`; domain models extend `_assets/models/Model.php`; Twig
views are rendered by controllers. New controllers require routing in
`index.php`.

- Use the existing PDO wrapper methods on `$this->db`; preserve parameterized
  query conventions and existing transaction handling.
- Preserve session-based authentication and permission checks. Inspect the
  adjacent controller/model before choosing the authorization pattern.
- Keep server validation and business rules on the server even when UI
  validation exists.
- Validate changed PHP with `php -l <file>` when PHP is available. There is no
  configured automated backend test suite.
