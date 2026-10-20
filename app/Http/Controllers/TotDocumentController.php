<?php

namespace App\Http\Controllers;

use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminSession;
use App\Services\AdminApi\ApiLogger;
use App\Services\Documents\DocumentScanner;
use App\Services\Tot\TotLayout;
use App\Support\EnforcementSwitches;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Vendor documents: the PDF goes from this server straight to the private S3 bucket (key tots/{tot id}/{random}.pdf), then it is
 * registered with the Admin (docs/API_CONTRACT.md "Documents"). Neither the TOT id nor a document id appears in a URL.
 */
class TotDocumentController extends Controller
{
    public function __construct(
        private readonly AdminApiClient $api,
        private readonly AdminSession $session,
        private readonly ApiLogger $logger,
    ) {}

    public function store(Request $request, DocumentScanner $scanner): RedirectResponse
    {
        $tot = $this->session->tot();
        if ($tot === null) {
            return redirect()->route('home');
        }

        $types = array_keys(TotLayout::documents());
        $request->validate([
            'type' => ['required', Rule::in($types)],
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.config('vob.docs_max_kb')],
        ], [
            'file.max' => 'The file must be a PDF of at most '.(config('vob.docs_max_kb') / 1024).' MB.',
            'file.mimes' => 'Only PDF files can be uploaded.',
            'file.mimetypes' => 'Only PDF files can be uploaded.',
        ]);

        $file = $request->file('file');
        $type = $request->string('type')->toString();

        // Extension and MIME are client-influenced: also require the PDF signature, and ask the scanner hook.
        $handle = fopen($file->getRealPath(), 'rb');
        $signature = $handle ? fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }
        if ($signature !== '%PDF-' || ! $scanner->isClean($file->getRealPath())) {
            return back()->withErrors(['document' => 'That file could not be accepted. Please upload a valid PDF.']);
        }

        $key = 'tots/'.$tot['id'].'/'.Str::random(40).'.pdf';
        $disk = EnforcementSwitches::skipThirdParty() ? 'local' : config('vob.docs_disk'); // demo mode: no S3

        if (! $this->putObject($disk, $key, $file->getRealPath())) {
            return back()->withErrors(['document' => 'We could not store the file right now. Please try again.']);
        }

        $response = $this->api->post('tots/'.$tot['id'].'/documents', [
            'type' => $type,
            'path' => $key,
            'original_name' => $this->safeName($file->getClientOriginalName()),
            'mime' => 'application/pdf',
            'size' => $file->getSize(),
        ]);

        if ($response->status() === 201) {
            return redirect()->route('home')->with('status', 'Document uploaded.');
        }

        $this->deleteObject($disk, $key); // registration failed: do not leave an orphan in the bucket

        $message = match ($response->status()) {
            409 => 'This Terms of Trade can no longer be edited.',
            422 => $response->json('errors.type.0') ?? $response->json('errors.path.0') ?? 'The Admin rejected the document.',
            default => 'We could not register the document right now. Please try again.',
        };

        return back()->withErrors(['document' => $message]);
    }

    /** Remove the document of one type. The Admin's document id is looked up server-side, never taken from the browser. */
    public function destroy(Request $request): RedirectResponse
    {
        $tot = $this->session->tot();
        $request->validate(['type' => ['required', Rule::in(array_keys(TotLayout::documents()))]]);
        if ($tot === null) {
            return redirect()->route('home');
        }

        $detail = $this->api->get('tots/'.$tot['id']);
        $document = collect((array) $detail->json('documents', []))->firstWhere('type', $request->string('type')->toString());

        if (! $document) {
            return redirect()->route('home');
        }

        $response = $this->api->delete('tots/'.$tot['id'].'/documents/'.$document['id']);

        if ($response->successful()) {
            return redirect()->route('home')->with('status', 'Document removed.');
        }

        return back()->withErrors(['document' => $response->status() === 409
            ? 'This Terms of Trade can no longer be edited.'
            : 'We could not remove the document right now. Please try again.']);
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
            $error = class_basename($e); // no bucket detail in the log row
        }

        $this->logger->record('s3', 'PUT', 's3://'.($disk === 's3' ? config('filesystems.disks.s3.bucket') : $disk).'/'.$key, [], null,
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

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}._ ()-]/u', '_', basename(str_replace('\\', '/', $name))) ?: 'document.pdf';

        return mb_substr($name, 0, 150);
    }
}
