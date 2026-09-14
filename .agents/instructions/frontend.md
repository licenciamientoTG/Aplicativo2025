# Frontend Instructions

Views are Twig templates under `views/<controller>/`. JavaScript normally
mirrors its controller in `_assets/js/`; styles live in `_assets/css/`. The UI
uses Bootstrap Material Design, jQuery, and bundled FontAwesome; there is no
bundler or transpiler.

- Follow nearby Twig, jQuery, and CSS patterns before adding new ones.
- Escape and validate data appropriately in the view; do not relocate server
  validation or business rules to JavaScript.
- Keep selectors and endpoint contracts compatible with existing controller
  conventions.
- Validate changed JavaScript with `node --check <file>` when Node is available;
  use focused manual browser verification only when it is feasible.
