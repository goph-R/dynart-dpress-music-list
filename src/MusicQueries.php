<?php

namespace Dynart\MusicList;

use Dynart\Micro\Entities\Query;
use Dynart\MusicList\Entity\Album;
use Dynart\MusicList\Entity\Track;

/**
 * The plugin's two listings, registered with the query factory like the core's
 *
 * Through the factory and not built with `new` where they are needed, for the reason the core's
 * are: a query made by hand is one no other plugin can ever attach a condition to.
 */
class MusicQueries {

    /** What the admin's track list may be sorted by - the name goes into the SQL */
    const TRACK_SORTABLE = ['id', 'title', 'slug', 'released_at'];

    const ALBUM_SORTABLE = ['id', 'title', 'slug'];

    /**
     * Tracks: `search` in the title, `album_id` for one album's, and the list options
     *
     * Newest first when nothing says otherwise, with the id under it - tracks imported together
     * can share a moment, and an order that is not total reshuffles between two pages.
     */
    public function tracks(array $context): Query {
        $query = new Query(Track::class);
        if (!empty($context['search'])) {
            $query->addCondition('`title` like :search', [':search' => '%'.$context['search'].'%']);
        }
        if (!empty($context['album_id'])) {
            $query->addCondition('`album_id` = :albumId', [':albumId' => (int)$context['album_id']]);
        }
        $this->applyListOptions($query, $context, self::TRACK_SORTABLE, ['released_at' => 'desc', 'id' => 'desc']);
        return $query;
    }

    public function albums(array $context): Query {
        $query = new Query(Album::class);
        if (!empty($context['search'])) {
            $query->addCondition('`title` like :search', [':search' => '%'.$context['search'].'%']);
        }
        $this->applyListOptions($query, $context, self::ALBUM_SORTABLE, ['title' => 'asc']);
        return $query;
    }

    /**
     * The order and the page
     *
     * `order_by` is checked against the whitelist **here as well** as in `ListRequest`, because a
     * second caller may build the context by hand and the name goes into the SQL.
     *
     * @param string[] $sortable
     * @param array<string, string> $default field => direction
     */
    protected function applyListOptions(Query $query, array $context, array $sortable, array $default): void {
        $orderBy = (string)($context['order_by'] ?? '');
        if ($orderBy !== '' && in_array($orderBy, $sortable, true)) {
            $query->addOrderBy($orderBy, ($context['order_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc');
        } else {
            foreach ($default as $field => $direction) {
                $query->addOrderBy($field, $direction);
            }
        }
        if (isset($context['max'])) {
            $query->setLimit((int)($context['offset'] ?? 0), (int)$context['max']);
        }
    }
}
