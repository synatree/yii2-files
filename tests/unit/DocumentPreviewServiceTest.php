<?php

namespace thyseus\files\tests\unit;

use PHPUnit\Framework\TestCase;
use thyseus\files\models\File;
use thyseus\files\services\DocumentPreviewService;
use thyseus\files\services\preview\PopplerPdfDriver;

class DocumentPreviewServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        DocumentPreviewService::resetDrivers();
        PopplerPdfDriver::resetCachedBinary();
        parent::tearDown();
    }

    public function testPreviewCacheKeyIsStable(): void
    {
        $file = new File([
            'id' => 'abc-123',
            'checksum' => 'deadbeefdeadbeefdeadbeefdeadbeef',
        ]);

        $service = new DocumentPreviewService();
        $key = $service->getPreviewCacheKey($file, 64, 64, '.png');

        $this->assertSame(32, strlen($key));
        $this->assertSame($key, $service->getPreviewCacheKey($file, 64, 64, '.png'));
    }

    public function testIsPreviewKeyForFileMatchesGeneratedKey(): void
    {
        $file = new File([
            'id' => 'file-1',
            'checksum' => 'abc123abc123abc123abc123abc123',
        ]);

        $service = new DocumentPreviewService();
        $key = $service->getPreviewCacheKey($file, 128, 128, '.png');

        $this->assertTrue($service->isPreviewKeyForFile($file, $key . '.png'));
        $this->assertFalse($service->isPreviewKeyForFile($file, 'notavalidpreviewfilename.png'));
    }

    public function testFileIsPdfByMimeOrExtension(): void
    {
        $byMime = new File(['mimetype' => 'application/pdf', 'filename_user' => 'doc']);
        $byExt = new File(['mimetype' => 'application/octet-stream', 'filename_user' => 'report.PDF']);

        $this->assertTrue($byMime->isPdf());
        $this->assertTrue($byExt->isPdf());
    }

    public function testHasReadableBinaryFalseWhenPathMissing(): void
    {
        $file = new File(['filename_path' => null]);
        $this->assertFalse($file->hasReadableBinary());

        $file->filename_path = __DIR__ . '/../fixtures/does-not-exist.pdf';
        $this->assertFalse($file->hasReadableBinary());
    }
}
