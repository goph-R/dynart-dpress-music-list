<?php

namespace Dynart\MusicList\Test;

use Dynart\MusicList\MusicQueries;
use PHPUnit\Framework\TestCase;

/**
 * What a listing is ordered and narrowed by
 *
 * @covers \Dynart\MusicList\MusicQueries
 */
class MusicQueriesTest extends TestCase {

    public function testTracksAreNewestFirstUntilSomethingSaysOtherwise(): void {
        $query = (new MusicQueries())->tracks([]);
        $this->assertSame([['released_at', 'desc'], ['id', 'desc']], $query->orderBy());
        $this->assertSame([], $query->conditions());
    }

    /**
     * The name goes into the SQL, so it is checked here as well as in `ListRequest` - a second
     * caller may build the context by hand
     */
    public function testAnOrderThatIsNotAColumnIsNotUsed(): void {
        $query = (new MusicQueries())->tracks(['order_by' => 'id; drop table dp_user', 'order_dir' => 'desc']);
        $this->assertSame([['released_at', 'desc'], ['id', 'desc']], $query->orderBy());

        $query = (new MusicQueries())->tracks(['order_by' => 'audio_media_id']);
        $this->assertSame([['released_at', 'desc'], ['id', 'desc']], $query->orderBy(), 'a real column that is not on the list');
    }

    public function testAnOrderOnTheListIsUsedAndItsDirectionIsOneOfTwo(): void {
        $query = (new MusicQueries())->tracks(['order_by' => 'title', 'order_dir' => 'sideways']);
        $this->assertSame([['title', 'asc']], $query->orderBy());
    }

    public function testOneAlbumsTracksAreNarrowedToIt(): void {
        $query = (new MusicQueries())->tracks(['album_id' => '4', 'search' => 'fox']);
        $this->assertCount(2, $query->conditions());
        $this->assertSame([':search' => '%fox%', ':albumId' => 4], $query->variables());
    }

    public function testAPageIsAnOffsetAndALength(): void {
        $query = (new MusicQueries())->tracks(['offset' => 25, 'max' => 25]);
        $this->assertSame(25, $query->offset());
        $this->assertSame(25, $query->max());
    }

    public function testAlbumsAreByTitle(): void {
        $this->assertSame([['title', 'asc']], (new MusicQueries())->albums([])->orderBy());
    }
}
