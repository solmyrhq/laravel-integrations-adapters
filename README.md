# Laravel Integrations Adapters

[![CI](https://github.com/solmyrhq/laravel-integrations-adapters/actions/workflows/ci.yml/badge.svg)](https://github.com/solmyrhq/laravel-integrations-adapters/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/pocketarc/laravel-integrations-adapters)](https://packagist.org/packages/pocketarc/laravel-integrations-adapters)
[![Total Downloads](https://img.shields.io/packagist/dt/pocketarc/laravel-integrations-adapters)](https://packagist.org/packages/pocketarc/laravel-integrations-adapters)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-8892BF?logo=php)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Adapter implementations for [`pocketarc/laravel-integrations`](https://github.com/solmyrhq/laravel-integrations). These aren't fully API-complete. They cover what's needed for the projects that use them. You can extend them or build your own using the core package's contracts.

## Installation

```bash
composer require pocketarc/laravel-integrations-adapters
```

The service provider auto-registers all four adapters; no `config/integrations.php` edits required.

## Available adapters

| Adapter  | Docs                                                                                                 |
|----------|------------------------------------------------------------------------------------------------------|
| GitHub   | [integrations.pocketarc.com/adapters/github](https://integrations.pocketarc.com/adapters/github)     |
| Postmark | [integrations.pocketarc.com/adapters/postmark](https://integrations.pocketarc.com/adapters/postmark) |
| Stripe   | [integrations.pocketarc.com/adapters/stripe](https://integrations.pocketarc.com/adapters/stripe)     |
| Zendesk  | [integrations.pocketarc.com/adapters/zendesk](https://integrations.pocketarc.com/adapters/zendesk)   |

## License

MIT. See [LICENSE](LICENSE) for details.
