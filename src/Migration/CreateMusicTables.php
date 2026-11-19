<?php

namespace Dynart\MusicList\Migration;

use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\MusicList\Entity\Album;
use Dynart\MusicList\Entity\Track;

/**
 * The version sorts after every core migration, which is all the interleaving needs
 */
class CreateMusicTables implements MigrationInterface {

    public function __construct(private QueryExecutor $queryExecutor) {}

    public function version(): string {
        return '2026_11_19_001_create_music_tables';
    }

    public function up(): void {
        $this->queryExecutor->createTable(Album::class, true);
        $this->queryExecutor->createTable(Track::class, true);
    }
}
