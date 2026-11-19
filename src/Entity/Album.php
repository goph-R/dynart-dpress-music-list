<?php

namespace Dynart\MusicList\Entity;

use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * What a track may belong to: a name, and optionally a cover
 *
 * An entity rather than a word typed on each track, so renaming an album is one edit, a list of
 * one album is `{{ music_list(album='coolfox-ost') }}`, and a track with no cover of its own has
 * somewhere to get one from.
 */
#[Table(name: 'music_album')]
class Album extends Entity {

    protected static string $eventName = 'music_album';

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    /** What the shortcode names an album by */
    #[Column(type: Column::TYPE_STRING, size: 200, notNull: true, unique: true)]
    public string $slug = '';

    #[Column(type: Column::TYPE_STRING, size: 255, notNull: true)]
    public string $title = '';

    /** The cover a track of the album shows when it has none of its own */
    #[Column(type: Column::TYPE_INT, index: true)]
    public ?int $cover_media_id = null;
}
