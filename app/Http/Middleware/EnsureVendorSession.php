<?php

namespace App\Http\Middleware;

use App\Services\AdminApi\AdminSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pages for a signed-in vendor. While the password must be changed, nothing else is reachable (Admin enforces it too). */
class EnsureVendorSession
{
    public function __construct(private readonly AdminSession $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->session->check()) {
            return redirect()->route('sign-in');
        }

        if ($this->session->expired()) {
            $this->session->forget(); // the invitation link stays so the vendor can sign in again

            return redirect()->route('sign-in')->with('status', 'Your session has ended. Please sign in again.');
        }
        $this->session->touch();

        if ($this->session->mustChangePassword() && ! $request->routeIs('password.*', 'logout')) {
            return redirect()->route('password.edit');
        }

        return $next($request);
    }
}
