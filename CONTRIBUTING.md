# Contributing to Aegis

## Development Setup

```bash
git clone https://github.com/your-org/aegis.git
cd aegis
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

## Running Tests

```bash
php artisan test                          # All tests
php artisan test --filter=ClassName       # Specific test class
php artisan test --parallel               # Parallel execution
```

## Creating a Plugin

```bash
php artisan aegis:plugin:create my-plugin
```

Plugin structure:
```
plugins/my-plugin/
├── aegis.json          # Manifest (name, version, tools, permissions)
├── src/
│   └── MyTool.php      # Tool implementation
└── tests/
    └── MyToolTest.php
```

## Plugin Signing

```bash
php artisan aegis:plugin:keygen
php artisan aegis:plugin:sign plugins/my-plugin
php artisan aegis:plugin:verify plugins/my-plugin
```

## Code Style

- Follow PSR-12
- Use strict types
- No comments unless absolutely necessary (complex algorithms, security, regex)
- Pest PHP for tests (not PHPUnit directly)

## Pull Request Process

1. Fork the repository
2. Create a feature branch: `git checkout -b feat/my-feature`
3. Write tests first (TDD)
4. Implement the feature
5. Run `php artisan test` — all must pass
6. Submit PR with clear description

## Messaging Adapter Development

Implement `App\Messaging\Contracts\MessagingAdapter`:

```php
class MyAdapter extends BaseAdapter
{
    public function sendMessage(string $channelId, string $content): void { }
    public function handleIncomingMessage(Request $request): IncomingMessage { }
    public function getName(): string { return 'my-platform'; }
    public function getCapabilities(): AdapterCapabilities { }
}
```

Register in `config/aegis.php` under `messaging.adapters`.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
