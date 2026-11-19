<?php

namespace Dynart\MusicList\Test;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\Query;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Query\QueryFactory;
use Dynart\Dpress\Service\MediaService;
use Dynart\MusicList\Entity\Album;
use Dynart\MusicList\Entity\Track;
use Dynart\MusicList\MusicList;
use Dynart\MusicList\MusicService;
use PHPUnit\Framework\TestCase;

/**
 * What a track may point at, and what a visitor is handed
 *
 * Two things here cannot be taken back once they are wrong in front of people: a track whose
 * "audio" is not audio is a player that plays nothing, and a slug that changed is every share
 * link somebody posted pointing at nothing. Both get tests, and so does the list a page renders.
 *
 * **Nothing here touches a database.** The entity manager and the library are mocks; what is
 * tested is what the service decides.
 *
 * @covers \Dynart\MusicList\MusicService
 */
class MusicServiceTest extends TestCase {

    /** @var array<int, Media> the library, by id */
    private array $library = [];

    /** @var array[] what the track and album queries answer with */
    private array $trackRows = [];
    private array $albumRows = [];

    /** @var array<string, array> the context each named query was last made with */
    private array $contexts = [];

    /** @var array<int, Album> */
    private array $albums = [];

    /** What `fetchOne` answers, or a function that does: 0 is "no such slug" and "nothing uses it" alike */
    private mixed $one = 0;

    /** @var array[] every `EntityManager::update()` call */
    private array $updates = [];

    private function media(int $id, string $category, string $fileName, bool $deleted = false): Media {
        $media = new Media();
        $media->id = $id;
        $media->category = $category;
        $media->file_name = $fileName;
        $media->path = '2026/11/'.$fileName;
        $media->deleted_at = $deleted ? '2026-11-01 00:00:00' : null;
        return $this->library[$id] = $media;
    }

    private function service(): MusicService {
        $em = $this->createMock(EntityManager::class);
        $em->method('safeTableName')->willReturnCallback(fn(string $class) => '`'.basename(str_replace('\\', '/', $class)).'`');
        $em->method('findById')->willReturnCallback(
            fn(string $class, mixed $id) => $class === Album::class ? ($this->albums[(int)$id] ?? null) : null
        );
        $em->method('update')->willReturnCallback(function (...$arguments) {
            $this->updates[] = $arguments;
        });

        $db = $this->createMock(Database::class);
        $db->method('fetchOne')->willReturnCallback(fn() => is_callable($this->one) ? ($this->one)() : $this->one);

        $queries = $this->createMock(QueryFactory::class);
        $queries->method('create')->willReturnCallback(function (string $name, array $context = []) {
            $this->contexts[$name] = $context;
            return new Query($name === MusicList::QUERY_ALBUMS ? Album::class : Track::class);
        });
        $executor = $this->createMock(QueryExecutor::class);
        $executor->method('findAll')->willReturnCallback(
            fn(Query $query) => $query->from() === Album::class ? $this->albumRows : $this->trackRows
        );

        $media = $this->createMock(MediaService::class);
        $media->method('findById')->willReturnCallback(fn(int $id) => $this->library[$id] ?? null);
        // the real one leaves out what is in the bin, and that is the behaviour being relied on
        $media->method('findByIds')->willReturnCallback(function (array $ids) {
            $found = [];
            foreach ($ids as $id) {
                if (isset($this->library[$id]) && !$this->library[$id]->isDeleted()) {
                    $found[$id] = $this->library[$id];
                }
            }
            return $found;
        });
        $view = $this->createMock(MediaView::class);
        $view->method('url')->willReturnCallback(
            fn(Media $item, string $preset = '') => '/uploads/'.$item->path.($preset === '' ? '' : '?'.$preset)
        );

        return new MusicService(
            $em, $db, $executor, $queries, $this->createMock(EventServiceInterface::class),
            new Slugger(), $media, $view
        );
    }

    // --- what a track may point at ---

    /**
     * Somebody adding forty tracks wants this thirty-nine times
     */
    public function testATrackNobodyNamedIsCalledWhatItsFileIs(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'Fox in the Desert II.mp3');
        $track = $this->service()->createTrack(['audio_media_id' => 5]);

        $this->assertSame('Fox in the Desert II', $track->title);
        $this->assertSame('fox-in-the-desert-ii', $track->slug);
        $this->assertSame(5, $track->audio_media_id);
        $this->assertNotNull($track->released_at, 'a new track is dated now until somebody says otherwise');
    }

    /**
     * The picker offers audio files only, but what arrives is a number in a form
     */
    public function testTheAudioOfATrackHasToBeAudio(): void {
        $this->media(2, Media::CATEGORY_IMAGE, 'cover.jpg');
        $this->expectException(DpressException::class);
        $this->expectExceptionMessage("'cover.jpg' is not an audio file.");
        $this->service()->createTrack(['audio_media_id' => 2, 'title' => 'x']);
    }

    public function testTheCoverOfATrackHasToBeAnImage(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'a.mp3');
        $this->media(6, Media::CATEGORY_AUDIO, 'b.mp3');
        $this->expectException(DpressException::class);
        $this->service()->createTrack(['audio_media_id' => 5, 'cover_media_id' => 6]);
    }

    /**
     * An item in the bin should not be newly pointed at
     */
    public function testATrashedFileCannotBeChosen(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'a.mp3', true);
        $this->expectException(DpressException::class);
        $this->service()->createTrack(['audio_media_id' => 5]);
    }

    public function testATrackNeedsAnAudioFile(): void {
        $this->expectException(DpressException::class);
        $this->service()->createTrack(['title' => 'Silence']);
    }

    public function testAnAlbumThatIsGoneIsRefused(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'a.mp3');
        $this->expectException(DpressException::class);
        $this->expectExceptionMessage('That album does not exist.');
        $this->service()->createTrack(['audio_media_id' => 5, 'album_id' => 9]);
    }

    /**
     * A refused save leaves the entity as it was read, so the form showing it again shows what
     * is stored and not half of what was typed
     */
    public function testARefusedUpdateChangesNothing(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'a.mp3');
        $this->media(2, Media::CATEGORY_IMAGE, 'cover.jpg');
        $service = $this->service();
        $track = $service->createTrack(['audio_media_id' => 5, 'title' => 'Before']);
        try {
            $service->updateTrack($track, ['title' => 'After', 'audio_media_id' => 2]);
            $this->fail('an image was accepted as audio');
        } catch (DpressException $e) {
            $this->assertSame('Before', $track->title);
            $this->assertSame(5, $track->audio_media_id);
        }
    }

    // --- the slug ---

    /**
     * `?track=area-67` is in links people already posted. An import hands the old id over as the
     * slug, and it has to come out the way it went in
     */
    public function testAGivenSlugIsKept(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'AREA 67.mp3');
        $track = $this->service()->createTrack([
            'audio_media_id' => 5, 'title' => "Things are bad but we're escaping",
            'slug' => 'things-are-bad-but-were-escaping',
        ]);
        $this->assertSame('things-are-bad-but-were-escaping', $track->slug);
    }

    /**
     * Renaming a track must not move its address: the slug is only made from the title once
     */
    public function testRenamingATrackLeavesItsSlugAlone(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'a.mp3');
        $service = $this->service();
        $track = $service->createTrack(['audio_media_id' => 5, 'title' => 'Run']);
        $service->updateTrack($track, ['title' => 'Run (remastered)', 'slug' => '']);

        $this->assertSame('Run (remastered)', $track->title);
        $this->assertSame('run', $track->slug);
    }

    public function testASlugThatIsTakenGetsANumber(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'a.mp3');
        $service = $this->service();
        $asked = 0;
        // `run` is taken and so is `run-2`; the third candidate is free
        $this->one = function () use (&$asked) {
            return ++$asked <= 2 ? 1 : 0;
        };
        $track = $service->createTrack(['audio_media_id' => 5, 'title' => 'Run']);
        $this->assertSame('run-3', $track->slug);
    }

    public function testAnUpdateNamingOneFieldChangesOneField(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'a.mp3');
        $this->media(2, Media::CATEGORY_IMAGE, 'cover.jpg');
        $service = $this->service();
        $track = $service->createTrack(['audio_media_id' => 5, 'cover_media_id' => 2, 'title' => 'Run']);
        $service->updateTrack($track, ['released_at' => '2026-05-25 22:14:49']);

        $this->assertSame(2, $track->cover_media_id, 'a cover nobody mentioned is not a cover removed');
        $this->assertSame('2026-05-25 22:14:49', $track->released_at);
    }

    public function testACoverCanBeTakenOff(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'a.mp3');
        $this->media(2, Media::CATEGORY_IMAGE, 'cover.jpg');
        $service = $this->service();
        $track = $service->createTrack(['audio_media_id' => 5, 'cover_media_id' => 2, 'title' => 'Run']);
        $service->updateTrack($track, ['cover_media_id' => 0]);
        $this->assertNull($track->cover_media_id);
    }

    public function testATitleComesFromAFileName(): void {
        $this->assertSame('Fox in the Desert II', MusicService::titleFromFileName('Fox in the Desert II.mp3'));
        $this->assertSame('AX-7', MusicService::titleFromFileName(' AX-7.flac '));
        $this->assertSame('80386', MusicService::titleFromFileName('80386'));
        $this->assertSame('Untitled', MusicService::titleFromFileName('.mp3'));
    }

    // --- the list a visitor gets ---

    private function trackRow(int $id, string $slug, int $audio, ?int $cover = null, ?int $album = null): array {
        return [
            'id' => $id, 'slug' => $slug, 'title' => ucfirst($slug), 'audio_media_id' => $audio,
            'cover_media_id' => $cover, 'album_id' => $album, 'released_at' => '2026-05-25 22:14:49',
        ];
    }

    public function testATrackIsHandedOverWithItsFilesResolved(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'Run.mp3');
        $this->media(2, Media::CATEGORY_IMAGE, 'run.jpg');
        $this->trackRows = [$this->trackRow(1, 'run', 5, 2)];

        $this->assertSame([[
            'id'          => 'run',
            'title'       => 'Run',
            'url'         => '/uploads/2026/11/Run.mp3',
            'file'        => 'Run.mp3',
            'cover'       => '/uploads/2026/11/run.jpg?thumb',
            'cover_large' => '/uploads/2026/11/run.jpg?medium',
            'album'       => '',
            'released_at' => '2026-05-25 22:14:49',
        ]], $this->service()->playlist());
    }

    /**
     * A row that cannot play is worse than a row that is not there - trashed or purged alike
     */
    public function testATrackWhoseFileIsGoneIsLeftOffTheList(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'here.mp3');
        $this->media(6, Media::CATEGORY_AUDIO, 'binned.mp3', true);
        $this->trackRows = [
            $this->trackRow(1, 'here', 5),
            $this->trackRow(2, 'binned', 6),
            $this->trackRow(3, 'purged', 99),
        ];
        $this->assertSame(['here'], array_column($this->service()->playlist(), 'id'));
    }

    /**
     * A missing cover costs the cover and not the track
     */
    public function testATrackWhoseCoverIsGoneStillPlays(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'a.mp3');
        $this->trackRows = [$this->trackRow(1, 'run', 5, 77)];
        $playlist = $this->service()->playlist();
        $this->assertCount(1, $playlist);
        $this->assertSame('', $playlist[0]['cover']);
    }

    public function testATrackWithoutACoverShowsItsAlbums(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'a.mp3');
        $this->media(3, Media::CATEGORY_IMAGE, 'album.jpg');
        $this->albumRows = [['id' => 4, 'slug' => 'coolfox-ost', 'title' => 'CoolFox OST', 'cover_media_id' => 3]];
        $this->trackRows = [$this->trackRow(1, 'run', 5, null, 4)];

        $track = $this->service()->playlist()[0];
        $this->assertSame('CoolFox OST', $track['album']);
        $this->assertSame('/uploads/2026/11/album.jpg?thumb', $track['cover']);
    }

    public function testATracksOwnCoverComesBeforeItsAlbums(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'a.mp3');
        $this->media(2, Media::CATEGORY_IMAGE, 'own.jpg');
        $this->media(3, Media::CATEGORY_IMAGE, 'album.jpg');
        $this->albumRows = [['id' => 4, 'slug' => 'ost', 'title' => 'OST', 'cover_media_id' => 3]];
        $this->trackRows = [$this->trackRow(1, 'run', 5, 2, 4)];

        $this->assertSame('/uploads/2026/11/own.jpg?thumb', $this->service()->playlist()[0]['cover']);
    }

    /**
     * Somebody asked for one album; answering with every track is answering another question
     */
    public function testAnAlbumThatDoesNotExistIsAnEmptyList(): void {
        $this->media(5, Media::CATEGORY_AUDIO, 'a.mp3');
        $this->trackRows = [$this->trackRow(1, 'run', 5)];
        $this->assertSame([], $this->service()->playlist(['album' => 'no-such-album']));
    }

    public function testOneAlbumsListAsksForThatAlbum(): void {
        $album = new Album();
        $album->id = 4;
        $album->slug = 'coolfox-ost';
        $this->albums[4] = $album;
        $this->one = 4;   // the id the slug lookup finds
        $this->service()->playlist(['album' => 'coolfox-ost', 'sort' => 'title-asc']);

        $context = $this->contexts[MusicList::QUERY_TRACKS];
        $this->assertSame(4, $context['album_id']);
        $this->assertSame('title', $context['order_by']);
        $this->assertSame('asc', $context['order_dir']);
    }

    // --- the order ---

    public function testASortIsAFieldAndADirection(): void {
        $this->assertSame(['order_by' => 'released_at', 'order_dir' => 'desc'], MusicService::sortContext('created-desc'));
        $this->assertSame(['order_by' => 'released_at', 'order_dir' => 'asc'], MusicService::sortContext('created-asc'));
        $this->assertSame(['order_by' => 'title', 'order_dir' => 'desc'], MusicService::sortContext('title-desc'));
    }

    /**
     * It came out of somebody's document: a typo should cost the order and not the list
     */
    public function testASortNobodyKnowsIsTheDefault(): void {
        $this->assertSame(MusicService::sortContext(MusicList::DEFAULT_SORT), MusicService::sortContext('loudest'));
        $this->assertSame(MusicService::sortContext(MusicList::DEFAULT_SORT), MusicService::sortContext('id; drop table'));
    }

    // --- what the library is told ---

    /**
     * An mp3 a track plays is in no attachment row and in no markdown, so without this the
     * library calls it unused and purges it without a word
     */
    public function testAFileATrackUsesIsCountedAsUsed(): void {
        $usage = new class {
            public int $added = 0;
            public function mediaId(): int { return 12; }
            public function add(int $count): void { $this->added += $count; }
        };
        $this->one = 2;   // two tracks, and - the stub answering the same - two albums
        $this->service()->onMediaUsage($usage);
        $this->assertSame(4, $usage->added);
    }

    // --- albums ---

    /**
     * Deleting a name should not take the music with it
     */
    public function testDeletingAnAlbumKeepsItsTracks(): void {
        $album = new Album();
        $album->id = 4;
        $this->service()->deleteAlbum($album);

        $this->assertCount(1, $this->updates);
        [$class, $data, , $params] = $this->updates[0];
        $this->assertSame(Track::class, $class);
        $this->assertSame(['album_id' => null], $data);
        $this->assertSame([':albumId' => 4], $params);
    }

    public function testAnAlbumNeedsATitle(): void {
        $this->expectException(DpressException::class);
        $this->service()->createAlbum(['title' => '   ']);
    }

    public function testAnAlbumIsSluggedFromItsTitle(): void {
        $album = $this->service()->createAlbum(['title' => 'CoolFox OST']);
        $this->assertSame('coolfox-ost', $album->slug);
    }
}
