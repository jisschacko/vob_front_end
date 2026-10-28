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

    private const LINK = 'admin_api.link';

    private const TOTS = 'admin_api.tots';

    private const STARTED = 'admin_api.started_at';

    private const SEEN = 'admin_api.last_seen';

    /** Store the login response. The session id is regenerated to prevent session fixation. */
    public function start(array $login): void
    {
        session()->regenerate();
        session()->put(self::TOKEN, (string) $login['access_token']);
        session()->put(self::USER, $login['user'] ?? []);
        session()->put(self::TOT, $login['tot'] ?? null);
        session()->put(self::TOTS, array_values((array) ($login['tots'] ?? [])));
        session()->put(self::STARTED, time());
        session()->put(self::SEEN, time());
    }

    /** True when the vendor has been idle too long or has been signed in longer than the absolute limit (config/vob.php). */
    public function expired(): bool
    {
        $idle = (int) config('vob.session_idle_minutes') * 60;
        $absolute = (int) config('vob.session_absolute_minutes') * 60;
        $now = time();

        return ($idle > 0 && $now - (int) session(self::SEEN, $now) > $idle)
            || ($absolute > 0 && $now - (int) session(self::STARTED, $now) > $absolute);
    }

    /** Record activity (called on every signed-in request). */
    public function touch(): void
    {
        session()->put(self::SEEN, time());
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

    /** @return list<array<string,mixed>> every TOT of the vendor (login answer / GET /tots), newest first */
    public function tots(): array
    {
        return array_values((array) session(self::TOTS, []));
    }

    /** @param list<array<string,mixed>> $tots */
    public function rememberTots(array $tots): void
    {
        session()->put(self::TOTS, array_values($tots));
    }

    /**
     * Work on one of the vendor's TOTs. The browser only ever names it by its position in the list (never by id).
     *
     * @return array<string,mixed>|null the chosen TOT, or null when there is no such position
     */
    public function selectTot(int $index): ?array
    {
        $tot = $this->tots()[$index] ?? null;
        if (! is_array($tot) || ! isset($tot['id'])) {
            return null;
        }

        session()->put(self::TOT, $tot);
        session()->forget(['tot.locked', 'cashfree.pending']); // belonged to the previous TOT

        return $tot;
    }

    /** Where a signed-out vendor goes: back to the invitation sign-in when they came by link, otherwise the login page. */
    public function loginRoute(): string
    {
        return $this->link() !== null ? 'sign-in' : 'login';
    }

    /** @return array<string,mixed>|null the TOT behind the invitation link used to sign in */
    public function tot(): ?array
    {
        return session(self::TOT);
    }

    /**
     * Remember the resolved invitation link (kept server-side so the token leaves the URL right after /onboard/{token}).
     *
     * @param  array<string,mixed>  $resolved  the links/resolve response
     */
    public function rememberLink(string $token, array $resolved): void
    {
        session()->put(self::LINK, [
            'token' => $token,
            'vendor_name' => $resolved['vendor_name'] ?? null,
            'email_hint' => $resolved['email_hint'] ?? null,
            'financial_year' => $resolved['financial_year'] ?? null,
            'type' => $resolved['type'] ?? null,
            'first_time_login' => (bool) ($resolved['first_time_login'] ?? false),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function link(): ?array
    {
        return session(self::LINK);
    }

    public function forgetLink(): void
    {
        session()->forget(self::LINK);
    }

    /** Drop the token and vendor data (logout, 401, expiry). */
    public function forget(): void
    {
        session()->forget([self::TOKEN, self::USER, self::TOT, self::TOTS, self::STARTED, self::SEEN]);
        session()->regenerate();
    }
}
