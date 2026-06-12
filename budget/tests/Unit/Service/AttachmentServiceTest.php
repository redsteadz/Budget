<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Attachment;
use OCA\Budget\Db\AttachmentMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AttachmentService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AttachmentServiceTest extends TestCase {
    private AttachmentService $service;
    private AttachmentMapper $mapper;
    private TransactionMapper $transactionMapper;
    private Folder $userFolder;

    protected function setUp(): void {
        $this->mapper = $this->createMock(AttachmentMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->userFolder = $this->createMock(Folder::class);
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($this->userFolder);

        $this->service = new AttachmentService(
            $this->mapper,
            $this->transactionMapper,
            $rootFolder,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function ownTransaction(int $id = 5): void {
        $tx = new Transaction();
        $tx->setId($id);
        $tx->setDate('2026-06-01');
        $this->transactionMapper->method('find')->with($id, 'alice')->willReturn($tx);
    }

    private function makeAttachment(int $id, int $transactionId, int $fileId, string $name = 'receipt.png', string $mime = 'image/png'): Attachment {
        $attachment = new Attachment();
        $attachment->setId($id);
        $attachment->setTransactionId($transactionId);
        $attachment->setUserId('alice');
        $attachment->setFileId($fileId);
        $attachment->setFileName($name);
        $attachment->setMimeType($mime);
        $attachment->setCreatedAt('2026-06-01 12:00:00');
        return $attachment;
    }

    private function makeFile(int $fileId, string $name = 'receipt.png', string $mime = 'image/png'): File {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($fileId);
        $file->method('getName')->willReturn($name);
        $file->method('getMimeType')->willReturn($mime);
        return $file;
    }

    public function testWrongUserCannotList(): void {
        $this->transactionMapper->method('find')
            ->willThrowException(new DoesNotExistException('not yours'));
        $this->mapper->expects($this->never())->method('findByTransaction');

        $this->expectException(DoesNotExistException::class);
        $this->service->listForTransaction(5, 'alice');
    }

    public function testListFlagsMissingFiles(): void {
        $this->ownTransaction();
        $this->mapper->method('findByTransaction')->willReturn([
            $this->makeAttachment(1, 5, 101),
        ]);
        $this->userFolder->method('getById')->with(101)->willReturn([]);

        $result = $this->service->listForTransaction(5, 'alice');

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['missing']);
        $this->assertSame('receipt.png', $result[0]['fileName']); // snapshot survives
    }

    public function testListRefreshesNameFromLiveFile(): void {
        $this->ownTransaction();
        $this->mapper->method('findByTransaction')->willReturn([
            $this->makeAttachment(1, 5, 101, 'old-name.png'),
        ]);
        $this->userFolder->method('getById')->with(101)->willReturn([
            $this->makeFile(101, 'renamed.png', 'image/png'),
        ]);

        $result = $this->service->listForTransaction(5, 'alice');

        $this->assertFalse($result[0]['missing']);
        $this->assertSame('renamed.png', $result[0]['fileName']);
        $this->assertTrue($result[0]['isImage']);
    }

    public function testAttachExistingByPath(): void {
        $this->ownTransaction();
        $file = $this->makeFile(200, 'invoice.pdf', 'application/pdf');
        $this->userFolder->method('get')->with('/Documents/invoice.pdf')->willReturn($file);
        $this->mapper->method('findByTransaction')->willReturn([]);
        $this->mapper->method('insert')->willReturnArgument(0);

        $attachment = $this->service->attachExisting(5, 'alice', null, 'Documents/invoice.pdf');

        $this->assertSame(200, $attachment->getFileId());
        $this->assertSame('invoice.pdf', $attachment->getFileName());
        $this->assertSame(5, $attachment->getTransactionId());
    }

    public function testAttachExistingRejectsDisallowedMime(): void {
        $this->ownTransaction();
        $this->userFolder->method('getById')->with(200)->willReturn([
            $this->makeFile(200, 'archive.zip', 'application/zip'),
        ]);
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->attachExisting(5, 'alice', 200, null);
    }

    public function testAttachExistingRequiresIdOrPath(): void {
        $this->ownTransaction();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->attachExisting(5, 'alice', null, null);
    }

    public function testDuplicateAttachReturnsExistingRow(): void {
        $this->ownTransaction();
        $existing = $this->makeAttachment(9, 5, 200);
        $this->userFolder->method('getById')->with(200)->willReturn([$this->makeFile(200)]);
        $this->mapper->method('findByTransaction')->willReturn([$existing]);
        $this->mapper->expects($this->never())->method('insert');

        $attachment = $this->service->attachExisting(5, 'alice', 200, null);

        $this->assertSame(9, $attachment->getId());
    }

    public function testUploadRejectsFailedUpload(): void {
        $this->ownTransaction();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->upload(5, 'alice', ['error' => UPLOAD_ERR_PARTIAL, 'size' => 10]);
    }

    public function testUploadRejectsOversizedFile(): void {
        $this->ownTransaction();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->upload(5, 'alice', [
            'error' => UPLOAD_ERR_OK,
            'size' => 26214401,
            'tmp_name' => '/tmp/whatever',
            'name' => 'big.png',
        ]);
    }

    public function testDetachRemovesRowButNeverTouchesFile(): void {
        $this->ownTransaction();
        $attachment = $this->makeAttachment(9, 5, 200);
        $this->mapper->method('find')->with(9, 'alice')->willReturn($attachment);
        $this->mapper->expects($this->once())->method('delete')->with($attachment);
        // The file node is never even resolved during detach
        $this->userFolder->expects($this->never())->method('getById');

        $this->service->detach(5, 'alice', 9);
    }

    public function testDetachRejectsForeignAttachment(): void {
        $this->ownTransaction();
        $attachment = $this->makeAttachment(9, 999, 200); // belongs to another transaction
        $this->mapper->method('find')->with(9, 'alice')->willReturn($attachment);
        $this->mapper->expects($this->never())->method('delete');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->detach(5, 'alice', 9);
    }

    public function testGetCountsDelegatesToMapper(): void {
        $this->mapper->method('countsByUser')->with('alice')->willReturn([5 => 2, 7 => 1]);

        $this->assertSame([5 => 2, 7 => 1], $this->service->getCounts('alice'));
    }
}
