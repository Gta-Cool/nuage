<?php
/**
 * Copyright (c) 2012 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace Test\Files\Cache;

use OC\Files\Cache\CacheEntry;

/**
 * Class ScannerTest
 *
 * @group DB
 *
 * @package Test\Files\Cache
 */
class ScannerTest extends \Test\TestCase {
	/**
	 * @var \OC\Files\Storage\Storage $storage
	 */
	private $storage;

	/**
	 * @var \OC\Files\Cache\Scanner $scanner
	 */
	private $scanner;

	/**
	 * @var \OC\Files\Cache\Cache $cache
	 */
	private $cache;

	protected function setUp() {
		parent::setUp();

		$this->storage = new \OC\Files\Storage\Temporary(array());
		$this->scanner = new \OC\Files\Cache\Scanner($this->storage);
		$this->cache = new \OC\Files\Cache\Cache($this->storage);
	}

	protected function tearDown() {
		if ($this->cache) {
			$this->cache->clear();
		}

		parent::tearDown();
	}

	function testFile() {
		$data = "dummy file data\n";
		$this->storage->file_put_contents('foo.txt', $data);
		$this->scanner->scanFile('foo.txt');

		$this->assertEquals($this->cache->inCache('foo.txt'), true);
		$cachedData = $this->cache->get('foo.txt');
		$this->assertEquals($cachedData['size'], strlen($data));
		$this->assertEquals($cachedData['mimetype'], 'text/plain');
		$this->assertNotEquals($cachedData['parent'], -1); //parent folders should be scanned automatically

		$data = file_get_contents(\OC::$SERVERROOT . '/core/img/logo.png');
		$this->storage->file_put_contents('foo.png', $data);
		$this->scanner->scanFile('foo.png');

		$this->assertEquals($this->cache->inCache('foo.png'), true);
		$cachedData = $this->cache->get('foo.png');
		$this->assertEquals($cachedData['size'], strlen($data));
		$this->assertEquals($cachedData['mimetype'], 'image/png');
	}

	private function fillTestFolders() {
		$textData = "dummy file data\n";
		$imgData = file_get_contents(\OC::$SERVERROOT . '/core/img/logo.png');
		$this->storage->mkdir('folder');
		$this->storage->file_put_contents('foo.txt', $textData);
		$this->storage->file_put_contents('foo.png', $imgData);
		$this->storage->file_put_contents('folder/bar.txt', $textData);
	}

	function testFolder() {
		$this->fillTestFolders();

		$this->scanner->scan('');
		$this->assertEquals($this->cache->inCache(''), true);
		$this->assertEquals($this->cache->inCache('foo.txt'), true);
		$this->assertEquals($this->cache->inCache('foo.png'), true);
		$this->assertEquals($this->cache->inCache('folder'), true);
		$this->assertEquals($this->cache->inCache('folder/bar.txt'), true);

		$cachedDataText = $this->cache->get('foo.txt');
		$cachedDataText2 = $this->cache->get('foo.txt');
		$cachedDataImage = $this->cache->get('foo.png');
		$cachedDataFolder = $this->cache->get('');
		$cachedDataFolder2 = $this->cache->get('folder');

		$this->assertEquals($cachedDataImage['parent'], $cachedDataText['parent']);
		$this->assertEquals($cachedDataFolder['fileid'], $cachedDataImage['parent']);
		$this->assertEquals($cachedDataFolder['size'], $cachedDataImage['size'] + $cachedDataText['size'] + $cachedDataText2['size']);
		$this->assertEquals($cachedDataFolder2['size'], $cachedDataText2['size']);
	}

	function testShallow() {
		$this->fillTestFolders();

		$this->scanner->scan('', \OC\Files\Cache\Scanner::SCAN_SHALLOW);
		$this->assertEquals($this->cache->inCache(''), true);
		$this->assertEquals($this->cache->inCache('foo.txt'), true);
		$this->assertEquals($this->cache->inCache('foo.png'), true);
		$this->assertEquals($this->cache->inCache('folder'), true);
		$this->assertEquals($this->cache->inCache('folder/bar.txt'), false);

		$cachedDataFolder = $this->cache->get('');
		$cachedDataFolder2 = $this->cache->get('folder');

		$this->assertEquals(-1, $cachedDataFolder['size']);
		$this->assertEquals(-1, $cachedDataFolder2['size']);

		$this->scanner->scan('folder', \OC\Files\Cache\Scanner::SCAN_SHALLOW);

		$cachedDataFolder2 = $this->cache->get('folder');

		$this->assertNotEquals($cachedDataFolder2['size'], -1);

		$this->cache->correctFolderSize('folder');

		$cachedDataFolder = $this->cache->get('');
		$this->assertNotEquals($cachedDataFolder['size'], -1);
	}

	function testBackgroundScan() {
		$this->fillTestFolders();
		$this->storage->mkdir('folder2');
		$this->storage->file_put_contents('folder2/bar.txt', 'foobar');

		$this->scanner->scan('', \OC\Files\Cache\Scanner::SCAN_SHALLOW);
		$this->assertFalse($this->cache->inCache('folder/bar.txt'));
		$this->assertFalse($this->cache->inCache('folder/2bar.txt'));
		$cachedData = $this->cache->get('');
		$this->assertEquals(-1, $cachedData['size']);

		$this->scanner->backgroundScan();

		$this->assertTrue($this->cache->inCache('folder/bar.txt'));
		$this->assertTrue($this->cache->inCache('folder/bar.txt'));

		$cachedData = $this->cache->get('');
		$this->assertnotEquals(-1, $cachedData['size']);

		$this->assertFalse($this->cache->getIncomplete());
	}

	function testBackgroundScanOnlyRecurseIncomplete() {
		$this->fillTestFolders();
		$this->storage->mkdir('folder2');
		$this->storage->file_put_contents('folder2/bar.txt', 'foobar');

		$this->scanner->scan('', \OC\Files\Cache\Scanner::SCAN_SHALLOW);
		$this->assertFalse($this->cache->inCache('folder/bar.txt'));
		$this->assertFalse($this->cache->inCache('folder/2bar.txt'));
		$this->assertFalse($this->cache->inCache('folder2/bar.txt'));
		$this->cache->put('folder2', ['size' => 1]); // mark as complete

		$cachedData = $this->cache->get('');
		$this->assertEquals(-1, $cachedData['size']);

		$this->scanner->scan('', \OC\Files\Cache\Scanner::SCAN_RECURSIVE_INCOMPLETE, \OC\Files\Cache\Scanner::REUSE_ETAG | \OC\Files\Cache\Scanner::REUSE_SIZE);

		$this->assertTrue($this->cache->inCache('folder/bar.txt'));
		$this->assertTrue($this->cache->inCache('folder/bar.txt'));
		$this->assertFalse($this->cache->inCache('folder2/bar.txt'));

		$cachedData = $this->cache->get('');
		$this->assertNotEquals(-1, $cachedData['size']);

		$this->assertFalse($this->cache->getIncomplete());
	}

	public function testReuseExisting() {
		$this->fillTestFolders();

		$this->scanner->scan('');
		$oldData = $this->cache->get('');
		$this->storage->unlink('folder/bar.txt');
		$this->cache->put('folder', array('mtime' => $this->storage->filemtime('folder'), 'storage_mtime' => $this->storage->filemtime('folder')));
		$this->scanner->scan('', \OC\Files\Cache\Scanner::SCAN_SHALLOW, \OC\Files\Cache\Scanner::REUSE_SIZE);
		$newData = $this->cache->get('');
		$this->assertInternalType('string', $oldData['etag']);
		$this->assertInternalType('string', $newData['etag']);
		$this->assertNotSame($oldData['etag'], $newData['etag']);
		$this->assertEquals($oldData['size'], $newData['size']);

		$oldData = $newData;
		$this->scanner->scan('', \OC\Files\Cache\Scanner::SCAN_SHALLOW, \OC\Files\Cache\Scanner::REUSE_ETAG);
		$newData = $this->cache->get('');
		$this->assertSame($oldData['etag'], $newData['etag']);
		$this->assertEquals(-1, $newData['size']);

		$this->scanner->scan('', \OC\Files\Cache\Scanner::SCAN_RECURSIVE);
		$oldData = $this->cache->get('');
		$this->assertNotEquals(-1, $oldData['size']);
		$this->scanner->scanFile('', \OC\Files\Cache\Scanner::REUSE_ETAG + \OC\Files\Cache\Scanner::REUSE_SIZE);
		$newData = $this->cache->get('');
		$this->assertSame($oldData['etag'], $newData['etag']);
		$this->assertEquals($oldData['size'], $newData['size']);

		$this->scanner->scan('', \OC\Files\Cache\Scanner::SCAN_RECURSIVE, \OC\Files\Cache\Scanner::REUSE_ETAG + \OC\Files\Cache\Scanner::REUSE_SIZE);
		$newData = $this->cache->get('');
		$this->assertSame($oldData['etag'], $newData['etag']);
		$this->assertEquals($oldData['size'], $newData['size']);

		$this->scanner->scan('', \OC\Files\Cache\Scanner::SCAN_SHALLOW, \OC\Files\Cache\Scanner::REUSE_ETAG + \OC\Files\Cache\Scanner::REUSE_SIZE);
		$newData = $this->cache->get('');
		$this->assertSame($oldData['etag'], $newData['etag']);
		$this->assertEquals($oldData['size'], $newData['size']);
	}

	public function testRemovedFile() {
		$this->fillTestFolders();

		$this->scanner->scan('');
		$this->assertTrue($this->cache->inCache('foo.txt'));
		$this->storage->unlink('foo.txt');
		$this->scanner->scan('', \OC\Files\Cache\Scanner::SCAN_SHALLOW);
		$this->assertFalse($this->cache->inCache('foo.txt'));
	}

	public function testRemovedFolder() {
		$this->fillTestFolders();

		$this->scanner->scan('');
		$this->assertTrue($this->cache->inCache('folder/bar.txt'));
		$this->storage->rmdir('/folder');
		$this->scanner->scan('', \OC\Files\Cache\Scanner::SCAN_SHALLOW);
		$this->assertFalse($this->cache->inCache('folder'));
		$this->assertFalse($this->cache->inCache('folder/bar.txt'));
	}

	public function testScanRemovedFile() {
		$this->fillTestFolders();

		$this->scanner->scan('');
		$this->assertTrue($this->cache->inCache('folder/bar.txt'));
		$this->storage->unlink('folder/bar.txt');
		$this->scanner->scanFile('folder/bar.txt');
		$this->assertFalse($this->cache->inCache('folder/bar.txt'));
	}

	public function testETagRecreation() {
		$this->fillTestFolders();

		$this->scanner->scan('folder/bar.txt');

		// manipulate etag to simulate an empty etag
		$this->scanner->scan('', \OC\Files\Cache\Scanner::SCAN_SHALLOW, \OC\Files\Cache\Scanner::REUSE_ETAG);
		/** @var CacheEntry $data0 */
		$data0 = $this->cache->get('folder/bar.txt');
		$this->assertInternalType('string', $data0['etag']);
		$data1 = $this->cache->get('folder');
		$this->assertInternalType('string', $data1['etag']);
		$data2 = $this->cache->get('');
		$this->assertInternalType('string', $data2['etag']);
		$data0['etag'] = '';
		$this->cache->put('folder/bar.txt', $data0->getData());

		// rescan
		$this->scanner->scan('folder/bar.txt', \OC\Files\Cache\Scanner::SCAN_SHALLOW, \OC\Files\Cache\Scanner::REUSE_ETAG);

		// verify cache content
		$newData0 = $this->cache->get('folder/bar.txt');
		$this->assertInternalType('string', $newData0['etag']);
		$this->assertNotEmpty($newData0['etag']);
	}

	public function testRepairParent() {
		$this->fillTestFolders();
		$this->scanner->scan('');
		$this->assertTrue($this->cache->inCache('folder/bar.txt'));
		$oldFolderId = $this->cache->getId('folder');

		// delete the folder without removing the childs
		$sql = 'DELETE FROM `*PREFIX*filecache` WHERE `fileid` = ?';
		\OC_DB::executeAudited($sql, array($oldFolderId));

		$cachedData = $this->cache->get('folder/bar.txt');
		$this->assertEquals($oldFolderId, $cachedData['parent']);
		$this->assertFalse($this->cache->inCache('folder'));

		$this->scanner->scan('');

		$this->assertTrue($this->cache->inCache('folder'));
		$newFolderId = $this->cache->getId('folder');
		$this->assertNotEquals($oldFolderId, $newFolderId);

		$cachedData = $this->cache->get('folder/bar.txt');
		$this->assertEquals($newFolderId, $cachedData['parent']);
	}

	public function testRepairParentShallow() {
		$this->fillTestFolders();
		$this->scanner->scan('');
		$this->assertTrue($this->cache->inCache('folder/bar.txt'));
		$oldFolderId = $this->cache->getId('folder');

		// delete the folder without removing the childs
		$sql = 'DELETE FROM `*PREFIX*filecache` WHERE `fileid` = ?';
		\OC_DB::executeAudited($sql, array($oldFolderId));

		$cachedData = $this->cache->get('folder/bar.txt');
		$this->assertEquals($oldFolderId, $cachedData['parent']);
		$this->assertFalse($this->cache->inCache('folder'));

		$this->scanner->scan('folder', \OC\Files\Cache\Scanner::SCAN_SHALLOW);

		$this->assertTrue($this->cache->inCache('folder'));
		$newFolderId = $this->cache->getId('folder');
		$this->assertNotEquals($oldFolderId, $newFolderId);

		$cachedData = $this->cache->get('folder/bar.txt');
		$this->assertEquals($newFolderId, $cachedData['parent']);
	}

	/**
	 * @dataProvider dataTestIsPartialFile
	 *
	 * @param string $path
	 * @param bool $expected
	 */
	public function testIsPartialFile($path, $expected) {
		$this->assertSame($expected,
			$this->scanner->isPartialFile($path)
		);
	}

	public function dataTestIsPartialFile() {
		return [
			['foo.txt.part', true],
			['/sub/folder/foo.txt.part', true],
			['/sub/folder.part/foo.txt', true],
			['foo.txt', false],
			['/sub/folder/foo.txt', false],
		];
	}

	public function failGetDataProvider() {
		return [
			// throws for empty path and "files" in home storage
			[true, false, '', true],
			[true, true, '', true],
			[true, false, 'files', true],
			[true, true, 'files', true],

			// doesn't throw for federated shares (non-home)
			[false, true, '', false],
			[false, true, 'files', false],

			// doesn't throw for external storage (non-home)
			[false, false, '', false],
			[false, false, 'files', false],

			// doesn't throw for other paths
			[true, false, 'other', false],

			// doesn't throw if metadata exists
			[true, false, 'other', false, []],
		];
	}

	/**
	 * @dataProvider failGetDataProvider
	 */
	public function testFailGetData($isHomeStorage, $isSharedStorage, $scanPath, $expectedThrown, $metadata = null) {
		$this->storage = $this->getMock('\OC\Files\Storage\Storage');
		$this->storage->method('getCache')->willReturn($this->getMock('\OCP\Files\Cache\ICache'));
		$this->storage->expects($this->any())
			->method('getMetaData')
			->willReturn(null);
		$this->storage->expects($this->any())
			->method('instanceOfStorage')
			->will($this->returnValueMap([
				['\OCP\Files\IHomeStorage', $isHomeStorage],
				['\OCA\Files_Sharing\ISharedStorage', $isSharedStorage],
			]));
		$this->scanner = new \OC\Files\Cache\Scanner($this->storage);
		$thrown = false;
		try {
			$this->scanner->scanFile($scanPath);
		} catch (\OCP\Files\StorageNotAvailableException $e) {
			$thrown = true;
		}

		$this->assertEquals($expectedThrown, $thrown);
	}

}
