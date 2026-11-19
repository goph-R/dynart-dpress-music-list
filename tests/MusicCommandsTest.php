<?php

namespace Dynart\MusicList\Test;

use Dynart\MusicList\MusicCommands;
use PHPUnit\Framework\TestCase;

/**
 * Reading a MusicList folder
 *
 * The import itself is the service's calls in a loop; what is worth testing is what it reads -
 * a list is a file somebody handed over, and three things about one go wrong quietly.
 *
 * @covers \Dynart\MusicList\MusicCommands
 */
class MusicCommandsTest extends TestCase {

    private string $folder = '';

    protected function setUp(): void {
        $this->folder = sys_get_temp_dir().'/music-list-test-'.bin2hex(random_bytes(4));
        mkdir($this->folder.'/source/music', 0777, true);
        file_put_contents($this->folder.'/source/music/AREA 67.mp3', 'x');
        file_put_contents($this->folder.'/outside.mp3', 'x');
        $this->folder = (string)realpath($this->folder);
    }

    protected function tearDown(): void {
        @unlink($this->folder.'/source/music/AREA 67.mp3');
        @unlink($this->folder.'/outside.mp3');
        @rmdir($this->folder.'/source/music');
        @rmdir($this->folder.'/source');
        @rmdir($this->folder);
    }

    // --- the list ---

    /**
     * The `tracks.json` MusicList ships starts with a byte order mark, and `json_decode` reads
     * one as a syntax error - so the whole import would have said "not a list" about a list
     */
    public function testAByteOrderMarkIsNotASyntaxError(): void {
        $list = MusicCommands::readList("\xEF\xBB\xBF".'[{"id": "run", "title": "Run"}]');
        $this->assertSame([['id' => 'run', 'title' => 'Run']], $list);
    }

    public function testAnythingThatIsNotAListIsNotOne(): void {
        $this->assertNull(MusicCommands::readList(''));
        $this->assertNull(MusicCommands::readList('{"id": "run"}'));
        $this->assertNull(MusicCommands::readList('"tracks"'));
        $this->assertNull(MusicCommands::readList('[1, 2'));
    }

    public function testAnEmptyListIsAList(): void {
        $this->assertSame([], MusicCommands::readList('[]'));
    }

    public function testAnEntryThatIsNotATrackIsLeftOut(): void {
        $this->assertSame([['id' => 'run']], MusicCommands::readList('[7, {"id": "run"}, "x", null]'));
    }

    // --- the files ---

    /**
     * The list holds URL paths, and a title with a space in it is `%20` there
     */
    public function testAFileIsFoundByItsDecodedName(): void {
        $this->assertSame(
            $this->folder.'/source/music/AREA 67.mp3',
            MusicCommands::fileIn($this->folder.'/source', 'music/AREA%2067.mp3')
        );
    }

    /**
     * `../` in a list is not a thing to upload into a media library - checked on the resolved
     * path, because `music/../../outside.mp3` does not start with two dots
     */
    public function testAFileOutsideTheFolderIsNotFound(): void {
        $this->assertNull(MusicCommands::fileIn($this->folder.'/source', '../outside.mp3'));
        $this->assertNull(MusicCommands::fileIn($this->folder.'/source', 'music/../../outside.mp3'));
        $this->assertNull(MusicCommands::fileIn($this->folder.'/source', 'music/%2E%2E/%2E%2E/outside.mp3'));
    }

    public function testAMissingFileAndAFolderAreNotFiles(): void {
        $this->assertNull(MusicCommands::fileIn($this->folder.'/source', 'music/nothing.mp3'));
        $this->assertNull(MusicCommands::fileIn($this->folder.'/source', 'music'));
        $this->assertNull(MusicCommands::fileIn($this->folder.'/source', ''));
    }

    // --- the date ---

    public function testADateIsStoredAsUtc(): void {
        $this->assertSame('2026-05-25 22:14:49', MusicCommands::storedMoment('2026-05-25T22:14:49.000Z'));
        $this->assertSame('2026-05-25 20:14:49', MusicCommands::storedMoment('2026-05-25T22:14:49+02:00'));
    }

    /**
     * No date is no date: the track is then dated when it was imported, not 1970
     */
    public function testADateNobodyCanReadIsNoDate(): void {
        $this->assertNull(MusicCommands::storedMoment(''));
        $this->assertNull(MusicCommands::storedMoment('last tuesday-ish'));
    }
}
