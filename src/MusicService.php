<?php

namespace Dynart\MusicList;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Query\QueryFactory;
use Dynart\Dpress\Service\MediaService;
use Dynart\MusicList\Entity\Album;
use Dynart\MusicList\Entity\Track;

/**
 * Tracks and albums: every change to either goes through here
 *
 * The admin screens and the import command are both callers, and neither writes an entity
 * itself - a write that skipped this would skip the checks and the events with it, and be
 * invisible to anything listening.
 *
 * **What a track points at is checked, not trusted.** The picker only offers audio files for the
 * audio field, but a picker is what is *offered*; what arrives is a number in a form, and a track
 * whose "audio" is a PDF is a player that plays nothing.
 */
class MusicService {

    const EVENT_TRACK_CREATED = 'music.track:created';
    const EVENT_TRACK_UPDATED = 'music.track:updated';
    const EVENT_TRACK_DELETED = 'music.track:deleted';
    const EVENT_ALBUM_CREATED = 'music.album:created';
    const EVENT_ALBUM_UPDATED = 'music.album:updated';
    const EVENT_ALBUM_DELETED = 'music.album:deleted';

    /** The size a row's cover is shown at, and the one the now-playing header and the lock screen get */
    const COVER_SMALL = 'thumb';
    const COVER_LARGE = 'medium';

    public function __construct(
        protected EntityManager $em,
        protected Database $db,
        protected QueryExecutor $queryExecutor,
        protected QueryFactory $queries,
        protected EventServiceInterface $events,
        protected Slugger $slugger,
        protected MediaService $media,
        protected MediaView $mediaView,
    ) {}

    // --- Tracks ---

    public function findTrack(int $id): ?Track {
        $track = $this->em->findById(Track::class, $id);
        return $track instanceof Track ? $track : null;
    }

    public function findTrackBySlug(string $slug): ?Track {
        $id = $this->idBySlug(Track::class, $slug);
        return $id === null ? null : $this->findTrack($id);
    }

    /**
     * @return array[] rows, as the query executor answers with
     */
    public function tracks(array $context = []): array {
        return $this->queryExecutor->findAll($this->queries->create(MusicList::QUERY_TRACKS, $context));
    }

    public function countTracks(array $context = []): int {
        return (int)$this->queryExecutor->findAllCount($this->queries->create(MusicList::QUERY_TRACKS, $context));
    }

    /**
     * @param array $data `audio_media_id` is required; `title`, `slug`, `cover_media_id`,
     *                    `album_id` and `released_at` (stored UTC) are not
     */
    public function createTrack(array $data): Track {
        $track = new Track();
        $track->created_at = gmdate('Y-m-d H:i:s');
        $track->released_at = $track->created_at;
        $this->fillTrack($track, $data);
        $this->em->save($track);
        $this->events->emit(self::EVENT_TRACK_CREATED, [$track]);
        return $track;
    }

    public function updateTrack(Track $track, array $data): void {
        $this->fillTrack($track, $data);
        $this->em->save($track);
        $this->events->emit(self::EVENT_TRACK_UPDATED, [$track]);
    }

    /**
     * Removes the track and leaves its files in the library
     *
     * A track is a reference to two media items, and deleting a reference is not deleting what
     * it pointed at - the rule an attachment follows. The files go when somebody trashes them.
     */
    public function deleteTrack(Track $track): void {
        $this->em->deleteById(Track::class, $track->id);
        $this->events->emit(self::EVENT_TRACK_DELETED, [$track]);
    }

    /**
     * Applies what was given, checking each thing a track points at
     *
     * Only the keys that are present are touched, so an update naming one field changes one
     * field. Everything is checked **before** anything is assigned: a refused save leaves the
     * entity as it was read, and the form that shows it again shows what is stored.
     */
    protected function fillTrack(Track $track, array $data): void {
        $audio = null;
        if (array_key_exists('audio_media_id', $data)) {
            $audio = $this->requireMedia((int)$data['audio_media_id'], Media::CATEGORY_AUDIO, 'an audio file');
        } elseif ($track->audio_media_id === 0) {
            throw new DpressException('A track needs an audio file.');
        }
        $cover = null;
        $hasCover = array_key_exists('cover_media_id', $data);
        if ($hasCover && (int)$data['cover_media_id'] > 0) {
            $cover = $this->requireMedia((int)$data['cover_media_id'], Media::CATEGORY_IMAGE, 'an image');
        }
        $hasAlbum = array_key_exists('album_id', $data);
        $albumId = $hasAlbum && (int)$data['album_id'] > 0 ? (int)$data['album_id'] : null;
        if ($albumId !== null && $this->findAlbum($albumId) === null) {
            throw new DpressException('That album does not exist.');
        }

        if ($audio !== null) {
            $track->audio_media_id = $audio->id;
        }
        if ($hasCover) {
            $track->cover_media_id = $cover?->id;
        }
        if ($hasAlbum) {
            $track->album_id = $albumId;
        }
        if (array_key_exists('title', $data) && trim((string)$data['title']) !== '') {
            $track->title = trim((string)$data['title']);
        }
        if ($track->title === '') {
            // a track nobody named is called what its file is called, which is what somebody
            // adding forty of them wants thirty-nine times
            $track->title = self::titleFromFileName(
                ($audio ?? $this->media->findById($track->audio_media_id))?->label() ?? ''
            );
        }
        if (array_key_exists('released_at', $data) && trim((string)$data['released_at']) !== '') {
            $track->released_at = (string)$data['released_at'];
        }
        $slug = trim((string)($data['slug'] ?? ''));
        if ($slug !== '' || $track->slug === '') {
            $track->slug = $this->uniqueSlug(Track::class, $slug !== '' ? $slug : $track->title, $track->id);
        }
    }

    /**
     * `Fox in the Desert II.mp3` is called `Fox in the Desert II`
     */
    public static function titleFromFileName(string $fileName): string {
        $title = trim((string)preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', trim($fileName)));
        return $title !== '' ? $title : 'Untitled';
    }

    // --- Albums ---

    public function findAlbum(int $id): ?Album {
        $album = $this->em->findById(Album::class, $id);
        return $album instanceof Album ? $album : null;
    }

    public function findAlbumBySlug(string $slug): ?Album {
        $id = $this->idBySlug(Album::class, $slug);
        return $id === null ? null : $this->findAlbum($id);
    }

    /**
     * @return array[] rows
     */
    public function albums(array $context = []): array {
        return $this->queryExecutor->findAll($this->queries->create(MusicList::QUERY_ALBUMS, $context));
    }

    public function countAlbums(array $context = []): int {
        return (int)$this->queryExecutor->findAllCount($this->queries->create(MusicList::QUERY_ALBUMS, $context));
    }

    /**
     * The album called this, made if there is none
     *
     * What an import needs: a list of tracks names its albums by their titles, not by ids.
     */
    public function findOrCreateAlbum(string $title): Album {
        $existing = $this->findAlbumBySlug($this->slugger->slugify($title));
        return $existing ?? $this->createAlbum(['title' => $title]);
    }

    public function createAlbum(array $data): Album {
        $album = new Album();
        $this->fillAlbum($album, $data);
        $this->em->save($album);
        $this->events->emit(self::EVENT_ALBUM_CREATED, [$album]);
        return $album;
    }

    public function updateAlbum(Album $album, array $data): void {
        $this->fillAlbum($album, $data);
        $this->em->save($album);
        $this->events->emit(self::EVENT_ALBUM_UPDATED, [$album]);
    }

    /**
     * Removes the album and keeps its tracks, which simply stop belonging to one
     *
     * Not a cascade: deleting a name should not take the music with it, and the core's rule for
     * a deleted page's children is the same one.
     */
    public function deleteAlbum(Album $album): void {
        $this->em->update(Track::class, ['album_id' => null], '`album_id` = :albumId', [':albumId' => $album->id]);
        $this->em->deleteById(Album::class, $album->id);
        $this->events->emit(self::EVENT_ALBUM_DELETED, [$album]);
    }

    protected function fillAlbum(Album $album, array $data): void {
        $title = trim((string)($data['title'] ?? $album->title));
        if ($title === '') {
            throw new DpressException('An album needs a title.');
        }
        $cover = null;
        $hasCover = array_key_exists('cover_media_id', $data);
        if ($hasCover && (int)$data['cover_media_id'] > 0) {
            $cover = $this->requireMedia((int)$data['cover_media_id'], Media::CATEGORY_IMAGE, 'an image');
        }
        $album->title = $title;
        if ($hasCover) {
            $album->cover_media_id = $cover?->id;
        }
        $slug = trim((string)($data['slug'] ?? ''));
        if ($slug !== '' || $album->slug === '') {
            $album->slug = $this->uniqueSlug(Album::class, $slug !== '' ? $slug : $title, $album->id);
        }
    }

    // --- The list a visitor gets ---

    /**
     * The tracks of a player, with every file already resolved to an address
     *
     * `album` (a slug) for one album's; `sort`, one of `MusicList::SORTS`. An album that does
     * not exist is an empty list rather than every track: somebody asked for one thing, and
     * answering with everything is answering a different question.
     *
     * **A track whose file is gone is left out**, trashed or purged alike - the rule the header
     * logo follows, and a row that cannot play is worse than a row that is not there. A missing
     * *cover* only costs the cover: the track's own, then its album's, then none, and the player
     * draws a placeholder for none.
     *
     * Three queries whatever the length of the list: the tracks, the albums, and one for every
     * media item on it.
     *
     * @return array[] `id` (the slug), `title`, `url`, `file`, `cover`, `cover_large`, `album`,
     *                 `released_at` - the last four '' or null when there is none
     */
    public function playlist(array $context = []): array {
        $query = self::sortContext((string)($context['sort'] ?? MusicList::DEFAULT_SORT));
        $albumSlug = trim((string)($context['album'] ?? ''));
        if ($albumSlug !== '') {
            $album = $this->findAlbumBySlug($albumSlug);
            if ($album === null) {
                return [];
            }
            $query['album_id'] = $album->id;
        }
        $rows = $this->tracks($query);
        $albums = [];
        foreach ($this->albums() as $album) {
            $albums[(int)$album['id']] = $album;
        }
        $mediaIds = [];
        foreach ($rows as $row) {
            $mediaIds[] = (int)$row['audio_media_id'];
            $mediaIds[] = (int)($row['cover_media_id'] ?? 0);
            $mediaIds[] = (int)($albums[(int)($row['album_id'] ?? 0)]['cover_media_id'] ?? 0);
        }
        $media = $this->media->findByIds($mediaIds);

        $playlist = [];
        foreach ($rows as $row) {
            $audio = $media[(int)$row['audio_media_id']] ?? null;
            if ($audio === null || $audio->category !== Media::CATEGORY_AUDIO) {
                continue;
            }
            $album = $albums[(int)($row['album_id'] ?? 0)] ?? null;
            $cover = $media[(int)($row['cover_media_id'] ?? 0)]
                ?? $media[(int)($album['cover_media_id'] ?? 0)]
                ?? null;
            $playlist[] = [
                'id'          => (string)$row['slug'],
                'title'       => (string)$row['title'],
                'url'         => $this->mediaView->url($audio),
                'file'        => $audio->file_name,
                'cover'       => $cover === null ? '' : $this->mediaView->url($cover, self::COVER_SMALL),
                'cover_large' => $cover === null ? '' : $this->mediaView->url($cover, self::COVER_LARGE),
                'album'       => $album === null ? '' : (string)$album['title'],
                'released_at' => $row['released_at'] ?? null,
            ];
        }
        return $playlist;
    }

    /**
     * A sort's name as the two things a query is ordered by
     *
     * A name nobody knows is the default rather than an error: it came out of somebody's
     * document, and a typo in a shortcode should cost the order and not the list.
     *
     * @return array<string, string> `order_by` and `order_dir`
     */
    public static function sortContext(string $sort): array {
        if (!in_array($sort, MusicList::SORTS, true)) {
            $sort = MusicList::DEFAULT_SORT;
        }
        [$field, $direction] = explode('-', $sort);
        return [
            'order_by'  => $field === 'title' ? 'title' : 'released_at',
            'order_dir' => $direction === 'desc' ? 'desc' : 'asc',
        ];
    }

    // --- What the library is told ---

    /**
     * A file a track plays or shows is in use, and the library has no other way to know
     *
     * `MediaService::usageCount()` reads the core's own tables; this is the answer for ours, so
     * purging an mp3 that a track plays warns the way purging a picture a post shows does.
     * Subscribed to `media:usage_counted`. The argument is the core's `MediaUsage` and is not
     * type-hinted, so the plugin still loads on a Dpress from before the event existed - where
     * nothing emits it and this is never called.
     */
    public function onMediaUsage(object $usage): void {
        $id = (int)$usage->mediaId();
        $tracks = (int)$this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(Track::class)
                .' where `audio_media_id` = :audio or `cover_media_id` = :cover',
            [':audio' => $id, ':cover' => $id]
        );
        $albums = (int)$this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(Album::class).' where `cover_media_id` = :cover',
            [':cover' => $id]
        );
        $usage->add($tracks + $albums);
    }

    // --- Shared ---

    /**
     * The media item, or a sentence about why it cannot be used
     *
     * Trashed counts as gone: an item somebody put in the bin should not be newly pointed at.
     */
    protected function requireMedia(int $id, string $category, string $what): Media {
        $media = $id > 0 ? $this->media->findById($id) : null;
        if ($media === null || $media->isDeleted()) {
            throw new DpressException(ucfirst($what).' has to be chosen from the library.');
        }
        if ($media->category !== $category) {
            // the file's name and not its title: a track's cover is often titled after the
            // track, and "'Land of Sand' is not an audio file" about a picture called that
            // reads as the form arguing with itself
            throw new DpressException("'".$media->file_name."' is not $what.");
        }
        return $media;
    }

    protected function idBySlug(string $className, string $slug): ?int {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $id = $this->db->fetchOne(
            'select `id` from '.$this->em->safeTableName($className).' where `slug` = :slug',
            [':slug' => $slug]
        );
        return $id === false || $id === null ? null : (int)$id;
    }

    protected function uniqueSlug(string $className, string $text, int $exceptId): string {
        return $this->slugger->unique($text, function (string $candidate) use ($className, $exceptId) {
            return (int)$this->db->fetchOne(
                'select count(1) from '.$this->em->safeTableName($className)
                    .' where `slug` = :slug and `id` <> :id',
                [':slug' => $candidate, ':id' => $exceptId]
            ) > 0;
        });
    }
}
