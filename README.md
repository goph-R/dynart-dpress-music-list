# dynart-dpress-music-list

A music player for [Dpress](https://github.com/goph-R/dynart-dpress): a list of tracks with
covers and albums, a now-playing header with a visualizer, shuffle, sort, and share links that
open on the track - and at the second - somebody was listening to.

It is [MusicList](https://github.com/DynartInteractive/MusicList), a static page reading a `tracks.json`,
rebuilt as a part of a site. The tracks are rows edited in the admin, the mp3s and the covers are
items of the media library, and the player is one line on an ordinary page:

```
{{ music_list() }}
```

## Installing

Needs Dpress **0.96.0** or later.

```bash
cd plugins
git clone https://github.com/goph-R/dynart-dpress-music-list.git music-list
cd ..
vendor/bin/dpress plugin:enable -name music-list
vendor/bin/dpress upgrade          # makes the two tables
```

Then **Music** in the admin's navigation, for whoever holds the `music.manage` permission (the
admin role holds every permission).

## Using it

1. **Music > New track.** Choose an audio file from the library, or drop one into the picker.
   Everything else is optional: a track nobody named takes the name of its file.
2. Write `{{ music_list() }}` on a page. The page is the player's address, its place in a menu
   and its title - there is no route or setting of the plugin's own.

| written | lists |
|---|---|
| `{{ music_list() }}` | every track, newest first |
| `{{ music_list(album='coolfox-ost') }}` | one album's, named by its slug |
| `{{ music_list(sort='title-asc') }}` | in another order to begin with |

`sort` is `created-desc` (the default), `created-asc`, `title-asc` or `title-desc`. It is the order
the list starts in; the visitor's own select changes it from there.

**The whole window**: a page's **Template** select in the editor offers *Music player, full
window*. The page is then the player and nothing around it - no site header, no sidebar, the list
scrolling with the window - which is what the standalone MusicList looked like. Without it the
player is a section of the page, in the theme's layout. A theme answers the template with a
`dpress/content/page-template-music-player.phtml` of its own.

**As the front page**: set **Settings > Front page** to the page's address, `/music`. From Dpress
0.97.0 the page is then rendered at `/` itself - the site's address is the player's, share links
are `/?track=<slug>`, and the page's own address sends a visitor there. That is what a site that
is only a player wants, and it keeps the links of a standalone MusicList that lived at the root
working.

**Uploading in the admin** is an ordinary media upload, so PHP's limits apply: with the stock
`upload_max_filesize = 2M` every mp3 is refused. Raise it and `post_max_size` in php.ini, or for
this site alone in a `public/.user.ini`, and `media.max_size` in `dpress.ini` for a track over
16 MB.

**Covers**: a track shows its own, then its album's, then a gradient drawn from its title. The
list uses the library's `thumb` size and the header its `medium`, so a 2 MB cover is not
downloaded forty times to be shown at 48 pixels.

**Share links** are the page's address with `?track=<slug>`, plus `&t=<seconds>` when the track
being shared is the one playing. Changing a track's slug breaks the links already posted, which
is why renaming a track leaves its slug alone.

## Coming from MusicList

```bash
vendor/bin/dpress music:import -source ../MusicList -user you@example.com -check   # what would happen
vendor/bin/dpress music:import -source ../MusicList -user you@example.com
```

Reads `tracks.json` and, for each entry, imports the mp3 and the cover into the media library,
makes the album if the entry names one, and adds the track. `-user` is who the files are uploaded
as. An entry's `id` becomes the track's slug, so `?track=area-67` keeps meaning the same track,
and `createdOn` becomes its release date.

**Safe to run again**: a track whose slug is already there is skipped, so a second run brings
over only what was added to the old list since. A file larger than `media.max_size` (16 MB unless
configured) is refused by the library, and the command says which.

## How it is built

- **Tracks and albums are two tables of the plugin's own**, `music_track` and `music_album`. Not a
  kind of content: a track has no markdown and nothing else a post has.
- **The files are media items, referenced by id.** They are uploaded, replaced, trashed and purged
  where every other file is. Deleting a track leaves its files in the library; a track whose file
  is gone drops off the public list and is marked in the admin.
- **The library is told what a track uses** (`media:usage_counted`), so purging an mp3 a track
  plays warns the way purging a picture a post shows does.
- **The list is rendered by the server.** Each row is a title, a date and a download link before
  any script runs; `music-list.js` turns the rows it finds into a player. No second request, and
  a page that is readable - and indexable - without JavaScript.
- **Nothing is loaded by a page without a player on it.** The script and the stylesheet are
  registered against the attribute the player's root carries.
- **No analytics and nothing from another origin.** The player fires a DOM event when a track
  starts, once per track, and a site that wants plays counted listens for it:

  ```js
  document.addEventListener('music-list:play', function (event) {
      // event.detail: {id, title, album}
  });
  ```

## Theming

The player is a dark surface of its own whatever the theme around it is. Its colours are custom
properties, so a theme sets values and overrides no rule:

```css
.music-list {
    --ml-accent: #ff8a3d;
    --ml-radius: 4px;
    --ml-top: 64px;      /* the height of a site header that stays on screen */
}
```

`--ml-ground`, `--ml-fg`, `--ml-fg-dim`, `--ml-accent`, `--ml-accent-hover`, `--ml-surface`,
`--ml-row-active`, `--ml-radius`, `--ml-top`. The now-playing header sticks to the top of the
window and the transport to the bottom while the list scrolls with the page; `--ml-top` is how far
down the header stops, for a theme whose own header would otherwise cover it.

The markup is `views/player.phtml`, and a theme replaces it with a `music_list/player.phtml` of
its own. The full-window page is `views/page-player.phtml`.

## What it does not do

- **Keep playing from page to page.** The front end is ordinary pages, and leaving the one with
  the player on it ends the music.
- **Upload many tracks at once from the admin.** One track, one form; the import command is the
  bulk way in.
- **Read a file's length or its tags.** The title is typed or taken from the file name, and the
  duration is whatever the browser reports once the track loads.

## Tests

```bash
node assets/music-list.test.js     # the player: a stub DOM, no dependency, no build step
composer install && vendor/bin/phpunit
```

The PHP tests need Dpress's classes to type against; `composer.json` resolves them out of a
checkout three folders up, which is where they are when this is cloned into a site's `plugins/`.

## License

MIT - see [LICENSE](LICENSE). Music a site serves with this is that site's, and is not covered by
this license.
