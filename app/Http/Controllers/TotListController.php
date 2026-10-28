<?php

namespace App\Http\Controllers;

use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A vendor with several Terms of Trade (one per financial year) chooses which one to open. TOTs are named by list position, never by id. */
class TotListController extends Controller
{
    public function __construct(
        private readonly AdminApiClient $api,
        private readonly AdminSession $session,
    ) {}

    public function index()
    {
        // fresh rows (the status changes while reviewers work); the login answer is the fallback
        $response = $this->api->get('tots');
        if ($response->successful() && is_array($response->json('data'))) {
            $this->session->rememberTots((array) $response->json('data'));
        }

        return view('tots', ['tots' => $this->session->tots(), 'current' => $this->session->tot()['id'] ?? null]);
    }

    public function select(Request $request): RedirectResponse
    {
        $data = $request->validate(['index' => ['required', 'integer', 'min:0', 'max:1000']]);

        if ($this->session->selectTot((int) $data['index']) === null) {
            return redirect()->route('tots.index')->withErrors(['index' => 'Please choose one of the Terms of Trade in the list.']);
        }

        return redirect()->route('home');
    }
}
