<?php

namespace App\Services\AdminApi;

/**
 * The vendor's Admin API session, kept in the server-side Laravel session only. The access token never reaches the browser
 * (the session cookie carries just the session id).
 */
class AdminSession
{
    private const TOKEN = 'admin_api.token';

    private const USER = 'admin_api.user';

    private const TOT = 'admin_api.tot';

    /** Store the login response. The session id is regenerated to prevent session fixation. */
    public function start(array $login): void
    {
        session()->regenerate();
        session()->put(self::TOKEN, (string) $login['access_token']);
        session()->put(self::USER, $login['user'] ?? []);
        session()->put(self::TOT, $login['tot'] ?? null);
    }

    public function token(): ?string
    {
        $token = session(self::TOKEN);

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function check(): bool
    {
        return $this->token() !== null;
    }

    /** @return array<string,mixed> id, name, email, must_change_password */
    public function user(): array
    {
        return session(self::USER, []);
    }

    public function userPublicId(): ?string
    {
        return $this->user()['id'] ?? null;
    }

    public function mustChangePassword(): bool
    {
        return (bool) ($this->user()['must_change_password'] ?? false);
    }

    public function markPasswordChanged(): void
    {
        session()->put(self::USER.'.must_change_password', false);
    }

    /** @return array<string,mixed>|null the TOT behind the invitation link used to sign in */
    public function tot(): ?array
    {
        return session(self::TOT);
    }

    /** Drop the token and vendor data (logout, 401, expiry). */
    public function forget(): void
    {
        session()->forget([self::TOKEN, self::USER, self::TOT]);
        session()->regenerate();
    }
}
