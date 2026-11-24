<?php

namespace App\Http\Controllers;

use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminSession;
use App\Services\AdminApi\ApiLogger;
use App\Services\Documents\DocumentScanner;
use App\Services\Documents\UploadInspector;
use App\Services\Tot\TotLayout;
use App\Support\EnforcementSwitches;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Vendor documents (PDF, jpg, png, webp; docx too for the additional files; every file is inspected first, see UploadInspector): the file is written to the shared documents folder (disk 'documents' = storage/app/documents -> /data/documents, path tots/{tot id}/{random}.pdf), then it is
 * registered with the Admin (docs/API_CONTRACT.md "Documents"). Neither the TOT id nor a document id appears in a URL.
 */
class TotDocumentController extends Controller
{
    public function __construct(
        private readonly AdminApiClient $api,
        private readonly AdminSession $session,
        private readonly ApiLogger $logger,
    ) {}

    public function store(Request $request, DocumentScanner $scanner, UploadInspector $inspector): RedirectResponse
    {
        $tot = $this->session->tot();
        if ($tot === null) {
            return redirect()->route('home');
        }

        $request->validate(['type' => ['required', Rule::in([...array_keys(TotLayout::documents()), TotLayout::ADDITIONAL])]]);
        $type = $request->string('type')->toString();
        $here = $this->anchor($type); // back to the section the vendor was in, not the top of the page
        $allowed = TotLayout::allowedExtensions($type);
        $names = strtoupper(implode(', ', $allowed));

        // Laravel validation: presence, size and a first type check (client name and MIME). The real checks follow, on the file content.
        $this->validateAt($request, $here, [
            'file' => ['required', 'file', 'extensions:'.implode(',', $allowed), 'mimetypes:'.implode(',', $this->mimeTypes($allowed)), 'max:'.config('vob.docs_max_kb')],
        ], [
            'file.max' => 'The file must be at most '.(config('vob.docs_max_kb') / 1024).' MB.',
            'file.extensions' => "Only $names files can be uploaded.",
            'file.mimetypes' => "Only $names files can be uploaded.",
            'file.uploaded' => 'The file could not be uploaded. It may be larger than the server allows.',
        ]);

        $file = $request->file('file');

        if ($type === TotLayout::ADDITIONAL) {
            $existing = collect((array) $this->api->get('tots/'.$tot['id'])->json('documents', []))->where('type', TotLayout::ADDITIONAL)->count();
            if ($existing >= (int) config('vob.docs_additional_max')) {
                return redirect()->to($here)->withErrors(['document' => 'You can attach at most '.config('vob.docs_additional_max').' additional files. Remove one first.']);
            }
        }

        // Extension, MIME and the file's own signature must agree; images are re-encoded, PDF / Word are checked inside. Storage uses the DETECTED type.
        $checked = $inspector->inspect($file->getRealPath(), $file->getClientOriginalName(), $allowed);
        if (! $checked['ok']) {
            return redirect()->to($here)->withErrors(['document' => $checked['error']]);
        }

        try {
            if (! $scanner->isClean($checked['path'])) {
                return redirect()->to($here)->withErrors(['document' => 'That file could not be accepted. Please upload a different file.']);
            }

            $key = 'tots/'.$tot['id'].'/'.Str::random(40).'.'.$checked['ext']; // never the client's name or extension
            $disk = EnforcementSwitches::skipThirdParty() ? 'local' : config('vob.docs_disk'); // demo mode: private local disk, no shared folder

            if (! $this->putObject($disk, $key, $checked['path'])) {
                return redirect()->to($here)->withErrors(['document' => 'We could not store the file right now. Please try again.']);
            }
            $size = (int) filesize($checked['path']);
        } finally {
            if ($checked['temp']) {
                @unlink($checked['path']);
            }
        }

        $response = $this->api->post('tots/'.$tot['id'].'/documents', [
            'type' => $type,
            'path' => $key,
            'original_name' => $this->safeName($file->getClientOriginalName(), $checked['ext']),
            'mime' => $checked['mime'],
            'size' => $size,
        ]);

        if ($response->status() === 201) {
            return redirect()->to($here)->with('status', 'Document uploaded.');
        }

        $this->deleteObject($disk, $key); // registration failed: do not leave an orphan file

        $message = match ($response->status()) {
            409 => 'This Terms of Trade can no longer be edited.',
            422 => $response->json('errors.type.0') ?? $response->json('errors.path.0') ?? $response->json('errors.mime.0') ?? 'The Admin rejected the document.',
            default => 'We could not register the document right now. Please try again.',
        };

        return redirect()->to($here)->withErrors(['document' => $message]);
    }

    /**
     * Remove a document. A fixed slot is found by its type; an additional file by the id the Admin gave it, which must belong to THIS TOT
     * (looked up in the Admin's own answer: an id the browser invents matches nothing).
     */
    public function destroy(Request $request): RedirectResponse
    {
        $tot = $this->session->tot();
        $request->validate([
            'type' => ['required', Rule::in([...array_keys(TotLayout::documents()), TotLayout::ADDITIONAL])],
            'document' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        if ($tot === null) {
            return redirect()->route('home');
        }

        $type = $request->string('type')->toString();
        $here = $this->anchor($type);
        $detail = $this->api->get('tots/'.$tot['id']);
        $documents = collect((array) $detail->json('documents', []))->where('type', $type);
        $document = $type === TotLayout::ADDITIONAL
            ? $documents->first(fn ($d) => (string) ($d['id'] ?? '') === $request->string('document')->toString() && $request->filled('document'))
            : $documents->first();

        if (! $document) {
            return redirect()->to($here);
        }

        $response = $this->api->delete('tots/'.$tot['id'].'/documents/'.$document['id']);

        if ($response->successful()) {
            return redirect()->to($here)->with('status', 'Document removed.');
        }

        return redirect()->to($here)->withErrors(['document' => $response->status() === 409
            ? 'This Terms of Trade can no longer be edited.'
            : 'We could not remove the document right now. Please try again.']);
    }

    /** The Home page at the card that holds this document slot, so the page opens where the vendor was (not at the top). */
    private function anchor(string $type): string
    {
        foreach (TotLayout::cards() as $card) {
            foreach ($card['blocks'] as $block) {
                $kind = $block[0] ?? null;
                if (($kind === 'documents' && in_array($type, $block[1], true)) || ($kind === 'additional_documents' && $type === TotLayout::ADDITIONAL)) {
                    return route('home').'#'.$card['id'];
                }
            }
        }

        return route('home');
    }

    /** Laravel validation whose failure also returns to the section (a plain validate() would go back to the top of the page). */
    private function validateAt(Request $request, string $here, array $rules, array $messages): void
    {
        try {
            $request->validate($rules, $messages);
        } catch (ValidationException $e) {
            throw $e->redirectTo($here);
        }
    }

    /** Content types the first (Laravel) check lets through; the inspector then proves the real type from the content. */
    private function mimeTypes(array $allowed): array
    {
        $mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        if (in_array('docx', $allowed, true)) {
            array_push($mimes, UploadInspector::mimeOf('docx'), 'application/zip', 'application/x-zip-compressed', 'application/octet-stream');
        }

        return $mimes;
    }

    private function putObject(string $disk, string $key, string $localPath): bool
    {
        $started = hrtime(true);
        $error = null;
        try {
            $stream = fopen($localPath, 'rb');
            Storage::disk($disk)->put($key, $stream, ['visibility' => 'private']);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (Throwable $e) {
            report($e);
            $error = class_basename($e); // no path detail in the log row
        }

        $this->logger->record('storage', 'PUT', $disk.'://'.$key, [], null,
            $error === null ? 200 : null, null, (int) ((hrtime(true) - $started) / 1e6), $this->session->userPublicId(), $error);

        return $error === null;
    }

    private function deleteObject(string $disk, string $key): void
    {
        try {
            Storage::disk($disk)->delete($key);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A display name only (never a path): odd characters removed, and the extension is the one the content proved. */
    private function safeName(string $name, string $ext): string
    {
        $name = preg_replace('/[^\p{L}\p{N}._ ()-]/u', '_', basename(str_replace('\\', '/', $name))) ?: 'document';
        $given = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($given !== $ext && ! ($ext === 'jpg' && $given === 'jpeg')) {
            $name = ($given === '' ? $name : pathinfo($name, PATHINFO_FILENAME)).'.'.$ext;
        }

        return mb_substr($name, -150);
    }
}
