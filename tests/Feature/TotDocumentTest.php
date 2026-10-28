<?php

use App\Models\ApiLog;
use App\Services\Documents\DocumentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

function pdf(string $name = 'pan card.pdf', int $kb = 10): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('x', $kb * 1024)."\n%%EOF");
}

beforeEach(function () {
    Storage::fake('s3'); // the private bucket
});

describe('upload to S3', function () {
    it('stores the PDF under tots/{id}/{random}.pdf and registers it with the Admin', function () {
        T::fake();

        $this->withSession(T::session())->post('/tot/documents', ['type' => 'pan_certificate', 'file' => pdf()])
            ->assertRedirect(route('home'))->assertSessionHas('status', 'Document uploaded.');

        $files = Storage::disk('s3')->allFiles();
        expect($files)->toHaveCount(1)
            ->and($files[0])->toMatch('#^tots/'.T::TOT_ID.'/[A-Za-z0-9]{40}\.pdf$#');

        $sent = T::sent('POST', 'tots/'.T::TOT_ID.'/documents');
        expect($sent)->toMatchArray(['type' => 'pan_certificate', 'path' => $files[0], 'original_name' => 'pan card.pdf', 'mime' => 'application/pdf'])
            ->and($sent['size'])->toBeGreaterThan(10000);
        // the key is 8-120 chars of [A-Za-z0-9._-] after the TOT folder (API contract)
        expect(basename($files[0]))->toMatch('/^[A-Za-z0-9._-]{8,120}$/');
    });

    it('logs the S3 write without any file content', function () {
        T::fake();
        $this->withSession(T::session())->post('/tot/documents', ['type' => 'rtgs_form', 'file' => pdf()]);

        $log = ApiLog::where('service', 's3')->sole();
        expect($log->method)->toBe('PUT')->and($log->response_status)->toBe(200)
            ->and($log->user_public_id)->toBe('USERPUBLICID0001')->and($log->request_body)->toBeNull();
    });

    it('rejects anything that is not a PDF of at most 5 MB, before touching S3 or the Admin', function (callable $file, string $type) {
        T::fake();

        $this->withSession(T::session())->from('/home')->post('/tot/documents', ['type' => $type, 'file' => $file()])
            ->assertSessionHasErrors();

        expect(Storage::disk('s3')->allFiles())->toBeEmpty();
        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/documents'))->toBeNull();
    })->with([
        'text file' => [fn () => UploadedFile::fake()->createWithContent('notes.txt', 'hello'), 'pan_certificate'],
        'image' => [fn () => UploadedFile::fake()->image('scan.png'), 'pan_certificate'],
        'pdf extension, not a pdf' => [fn () => UploadedFile::fake()->createWithContent('fake.pdf', 'MZ not a pdf at all'), 'pan_certificate'],
        'over 5 MB' => [fn () => pdf('big.pdf', 5200), 'pan_certificate'],
        'unknown type' => [fn () => pdf(), 'passport'],
    ]);

    it('requires a file', function () {
        T::fake();

        $this->withSession(T::session())->from('/home')->post('/tot/documents', ['type' => 'pan_certificate'])->assertSessionHasErrors('file');
    });

    it('asks the scanner hook and refuses an infected file', function () {
        T::fake();
        app()->bind(DocumentScanner::class, fn () => new class implements DocumentScanner
        {
            public function isClean(string $path): bool
            {
                return false;
            }
        });

        $this->withSession(T::session())->from('/home')->post('/tot/documents', ['type' => 'pan_certificate', 'file' => pdf()])
            ->assertSessionHasErrors('document');
        expect(Storage::disk('s3')->allFiles())->toBeEmpty();
    });

    it('removes the object again when the Admin refuses the registration', function (int $status, string $message) {
        T::fake(['POST tots/'.T::TOT_ID.'/documents' => Http::response(['error' => 'x'], $status)]);

        $this->withSession(T::session())->from('/home')->post('/tot/documents', ['type' => 'pan_certificate', 'file' => pdf()])
            ->assertSessionHasErrors(['document' => $message]);

        expect(Storage::disk('s3')->allFiles())->toBeEmpty();
    })->with([
        'not editable' => [409, 'This Terms of Trade can no longer be edited.'],
        'server error' => [500, 'We could not register the document right now. Please try again.'],
    ]);

    it('reports a storage failure without registering anything', function () {
        T::fake();
        config(['vob.docs_disk' => 'no_such_disk']);

        $this->withSession(T::session())->from('/home')->post('/tot/documents', ['type' => 'pan_certificate', 'file' => pdf()])
            ->assertSessionHasErrors(['document' => 'We could not store the file right now. Please try again.']);

        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/documents'))->toBeNull();
        expect(ApiLog::where('service', 's3')->sole()->error)->not->toBeNull();
    });

    it('strips path tricks and odd characters from the original file name', function () {
        T::fake();
        $this->withSession(T::session())->post('/tot/documents', ['type' => 'pan_certificate', 'file' => pdf('..\\..\\evil<script>.pdf')]);

        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/documents')['original_name'])->toBe('evil_script_.pdf');
    });

    it('is POST only, signed in only, and keeps ids out of the URL', function () {
        $this->get('/tot/documents')->assertStatus(405);
        $this->post('/tot/documents', ['type' => 'pan_certificate', 'file' => pdf()])->assertRedirect(route('login'));
        expect(route('tot.documents.store'))->not->toContain(T::TOT_ID);
    });

    it('limits upload attempts', function () {
        T::fake();
        foreach (range(1, 20) as $i) {
            $this->withSession(T::session())->post('/tot/documents', ['type' => 'pan_certificate', 'file' => pdf()])->assertStatus(302);
        }
        $this->withSession(T::session())->post('/tot/documents', ['type' => 'pan_certificate', 'file' => pdf()])->assertStatus(429);
    });
});

describe('remove a document', function () {
    it('finds the Admin document id server-side and deletes it', function () {
        T::fake(['DELETE tots/'.T::TOT_ID.'/documents/77' => Http::response([], 204)]);

        $this->withSession(T::session())->post('/tot/documents/remove', ['type' => 'pan_certificate'])
            ->assertRedirect(route('home'))->assertSessionHas('status', 'Document removed.');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/tots/'.T::TOT_ID.'/documents/77'));
    });

    it('ignores a document id sent by the browser and does nothing if that type has no document', function () {
        T::fake();

        $this->withSession(T::session())->post('/tot/documents/remove', ['type' => 'gst_certificate', 'id' => 77])->assertRedirect(route('home'));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    });

    it('rejects an unknown type', function () {
        T::fake();

        $this->withSession(T::session())->from('/home')->post('/tot/documents/remove', ['type' => 'passport'])->assertSessionHasErrors('type');
    });

    it('explains when the TOT can no longer be edited', function () {
        T::fake(['DELETE tots/'.T::TOT_ID.'/documents/77' => Http::response(['error' => 'not_editable'], 409)]);

        $this->withSession(T::session())->from('/home')->post('/tot/documents/remove', ['type' => 'pan_certificate'])
            ->assertSessionHasErrors(['document' => 'This Terms of Trade can no longer be edited.']);
    });
});

describe('the page', function () {
    it('has one upload form and one remove form per document slot, as POST with CSRF', function () {
        T::fake();
        $html = $this->withSession(T::session())->get('/home')->getContent();

        foreach (['pan_certificate', 'gst_certificate', 'msme_certificate', 'cancelled_cheque', 'rtgs_form', 'board_resolution'] as $type) {
            expect($html)->toMatch('/<form id="doc-up-'.$type.'" method="POST" action="[^"]+\/tot\/documents" enctype="multipart\/form-data">\s*<input type="hidden" name="_token"/')
                ->and($html)->toContain('form="doc-up-'.$type.'"');
        }
        expect($html)->toContain('form="doc-rm-pan_certificate"')           // the one uploaded document can be removed
            ->and($html)->not->toContain('form="doc-rm-gst_certificate"')   // nothing to remove there
            ->and($html)->toContain('downloads/RTGS_template.docx')
            ->and(file_exists(public_path('downloads/RTGS_template.docx')))->toBeTrue();
    });

    it('shows upload errors at the top of the form', function () {
        T::fake();
        $html = $this->withSession(T::session() + ['errors' => (new ViewErrorBag)->put('default', new MessageBag(['document' => ['Only PDF files can be uploaded.']]))])
            ->get('/home')->getContent();

        expect($html)->toContain('Only PDF files can be uploaded.');
    });
});
