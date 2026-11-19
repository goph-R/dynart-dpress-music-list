<?php

namespace Dynart\MusicList\Entity;

use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * One track: a title, and two references into the media library
 *
 * **The files are not here.** The mp3 and the cover are media items, referenced by id the way a
 * post references its featured image - so they are uploaded, replaced, trashed and purged where
 * every other file is, and a cover can be the same picture a post shows without being stored
 * twice.
 *
 * **No foreign key to `media`, on purpose.** `MediaService::purge()` deletes the row, and a
 * foreign key from a plugin's table would turn that into an integrity error the core knows nothing
 * about. A track whose file is gone is left off the public list and marked in the admin instead -
 * the answer a menu item gives when its target is gone.
 *
 * A table of its own rather than a kind of content: a track has no markdown, no lead, no
 * revisions worth keeping, and nothing a post has that it would use.
 *
 * Not audited, like menus and blocks: the files it points at are, and those are what somebody
 * asks about after the fact.
 */
#[Table(name: 'music_track')]
class Track extends Entity {

    protected static string $eventName = 'music_track';

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    /**
     * What a share link names: `?track=<slug>`
     *
     * The slug and not the id, so a link somebody posted keeps meaning the same track after an
     * export and an import, and reads as the track it is.
     */
    #[Column(type: Column::TYPE_STRING, size: 200, notNull: true, unique: true)]
    public string $slug = '';

    #[Column(type: Column::TYPE_STRING, size: 255, notNull: true)]
    public string $title = '';

    /** The media item that is played. Has to be of the `audio` category - the service checks */
    #[Column(type: Column::TYPE_INT, notNull: true, index: true)]
    public int $audio_media_id = 0;

    /** The cover, an `image`. None falls back to the album's, and then to a drawn placeholder */
    #[Column(type: Column::TYPE_INT, index: true)]
    public ?int $cover_media_id = null;

    #[Column(type: Column::TYPE_INT, index: true)]
    public ?int $album_id = null;

    /**
     * When the track was made, which is what "newest first" sorts by
     *
     * Its own column rather than `created_at`: the row is created when somebody gets round to
     * adding it, and a track imported from an older list would otherwise be dated the day of the
     * import. Stored UTC, like every other moment in the database.
     */
    #[Column(type: Column::TYPE_DATETIME, index: true)]
    public ?string $released_at = null;

    #[Column(type: Column::TYPE_DATETIME, notNull: true)]
    public ?string $created_at = null;
}
