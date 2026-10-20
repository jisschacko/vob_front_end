<?php

it('documents every .env.example key in docs/ENV_SETTINGS.md', function () {
    $example = file_get_contents(base_path('.env.example'));
    $doc = file_get_contents(base_path('docs/ENV_SETTINGS.md'));

    preg_match_all('/^#?\s*([A-Z][A-Z0-9_]+)=/m', $example, $m);
    $keys = array_unique($m[1]);
    $keys = array_filter($keys, fn ($k) => ! in_array($k, ['PHP_CLI_SERVER_WORKERS', 'APP_MAINTENANCE_STORE', 'CACHE_PREFIX', 'SESSION_SECURE_COOKIE'], true)); // commented hints

    expect($keys)->not->toBeEmpty();
    foreach ($keys as $key) {
        expect($doc)->toContain("`$key`");
    }
});

it('keeps .env.example loadable (no inline comments after values)', function () {
    foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [, $value] = explode('=', $line, 2);
        expect(trim($value, " \t\"'"))->not->toMatch('/\s+(#|\/\/)/', "inline comment in: $line");
    }

    expect(fn () => Dotenv\Dotenv::parse(file_get_contents(base_path('.env.example'))))->not->toThrow(Throwable::class);
});
