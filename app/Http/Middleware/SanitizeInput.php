<?php

namespace App\Http\Middleware;

use App\Support\TextSanitizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Removes HTML / script markup and control characters from everything a browser sends (form body, JSON body, query string) before any
 * controller sees it, so the Admin API never receives markup from VOB. Business characters (& ' / - ( ) ...) are kept; see TextSanitizer.
 * Secrets are skipped so they are never altered: passwords, tokens and the encrypted sign-in field.
 * (Output is escaped by Blade on every page; the Admin Panel must do the same and use parameterised queries: docs/ADMIN_PANEL_CHANGES.md.)
 */
class SanitizeInput
{
    /** @var list<string> */
    private const SKIP = ['password', 'current_password', 'new_password', 'password_confirmation', 'token', 'link_token', '_token', 'enc', 'email'];

    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->isJson() ? $request->json() : $request->request;
        $body->replace(TextSanitizer::deep($body->all(), self::SKIP));
        $request->query->replace(TextSanitizer::deep($request->query->all(), self::SKIP));

        return $next($request);
    }
}
