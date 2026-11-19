/**
 * The music player - what turns the list `{{ music_list() }}` rendered into something that plays
 *
 * No framework, no build step, and no request of its own: the tracks are the rows the server
 * already put on the page, each carrying what the player needs as `data-` attributes. A page
 * with no script still shows the list and its download links; this is what is added on top.
 *
 * Three parts, in the order they are below:
 *
 *   the helpers   pure functions - a time as text, a drawn cover, a share link
 *   the queue     which track is current, what next and previous mean, shuffle and sort.
 *                 It knows nothing about a page, which is what lets `music-list.test.js` run it
 *   the player    one per `[data-music-list]` on the page: the queue wired to an `<audio>`
 *                 element and to the markup
 *
 * Nothing is found by id, so two players on one page are two players - and starting one pauses
 * the other, because two tracks at once is never what somebody meant.
 */
(function (global) {
    'use strict';

    var MusicList = {};

    // ------------------------------------------------------------------------------------------
    // the helpers
    // ------------------------------------------------------------------------------------------

    /** `75.4` as `1:15`. Anything that is not a time - NaN before the metadata loads - is `0:00` */
    MusicList.formatTime = function (seconds) {
        if (!isFinite(seconds) || seconds < 0) {
            seconds = 0;
        }
        var minutes = Math.floor(seconds / 60);
        var rest = Math.floor(seconds % 60);
        return minutes + ':' + (rest < 10 ? '0' : '') + rest;
    };

    /**
     * A cover for a track that has none: a gradient worked out from the title
     *
     * From the title and not at random, so a track keeps its colours from one visit to the next
     * and a list of them looks chosen rather than broken.
     */
    MusicList.placeholderFor = function (title) {
        var hue = 0;
        for (var i = 0; i < title.length; i++) {
            hue = (hue * 31 + title.charCodeAt(i)) % 360;
        }
        var svg =
            '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120">' +
            '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">' +
            '<stop offset="0" stop-color="hsl(' + hue + ', 55%, 42%)"/>' +
            '<stop offset="1" stop-color="hsl(' + ((hue + 48) % 360) + ', 60%, 24%)"/>' +
            '</linearGradient></defs><rect width="120" height="120" fill="url(#g)"/>' +
            '<path d="M74 38v30a12 12 0 1 1-8-11V46l-20 4v26a12 12 0 1 1-8-11V44z" fill="rgba(255,255,255,0.85)"/>' +
            '</svg>';
        return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
    };

    /** The orders a list can be in. Titles compare the way the reader's language does */
    MusicList.comparators = {
        'title-asc': function (a, b) {
            return a.title.localeCompare(b.title, undefined, {sensitivity: 'base'});
        },
        'title-desc': function (a, b) {
            return b.title.localeCompare(a.title, undefined, {sensitivity: 'base'});
        },
        'created-desc': function (a, b) { return b.released - a.released; },
        'created-asc': function (a, b) { return a.released - b.released; }
    };

    MusicList.DEFAULT_SORT = 'created-desc';

    /**
     * The link that brings somebody back to this track: the page's own address with `?track=`
     *
     * `seconds` adds `&t=` - shared from the track that is playing, the link resumes where the
     * sharer was. Whatever else the address carries is kept, because on a site without URL
     * rewriting the page itself is a parameter; a stale `t` and the fragment are not.
     */
    MusicList.shareUrl = function (href, id, seconds) {
        var url = new URL(href);
        url.hash = '';
        url.searchParams.set('track', id);
        url.searchParams.delete('t');
        if (seconds > 0) {
            url.searchParams.set('t', String(Math.floor(seconds)));
        }
        return url.href;
    };

    /** What a link asked for: `{track: 'area-67', at: 42}`, or `{track: '', at: 0}` for nothing */
    MusicList.deepLink = function (search) {
        var params = new URLSearchParams(search);
        return {
            track: params.get('track') || '',
            at: Math.max(0, parseInt(params.get('t'), 10) || 0)
        };
    };

    /** A row's `data-` attributes as a track. An unreadable date sorts as the oldest there is */
    MusicList.readTrack = function (element) {
        var data = element.dataset;
        var released = Date.parse(data.released || '');
        return {
            id: data.id || '',
            title: data.title || '',
            url: data.url || '',
            cover: data.cover || '',
            coverLarge: data.coverLarge || '',
            album: data.album || '',
            released: isNaN(released) ? 0 : released,
            element: element
        };
    };

    // ------------------------------------------------------------------------------------------
    // the queue
    // ------------------------------------------------------------------------------------------

    /**
     * The tracks in the order they are shown, and which one is current
     *
     * The list on the page and the order of play are **one order**: sorting moves the rows and
     * `next()` follows them, so "next" is always the row below.
     *
     * @param {Array} tracks  objects with at least `id`, `title` and `released`
     * @param {Function} [random]  `Math.random` unless a test says otherwise
     */
    function Queue(tracks, random) {
        this.tracks = tracks.slice();
        this.current = -1;
        this.shuffle = false;
        /** indexes already visited, so Previous goes back through a shuffled order too */
        this.history = [];
        this.random = random || Math.random;
    }

    Queue.prototype.track = function () {
        return this.current === -1 ? null : this.tracks[this.current];
    };

    Queue.prototype.indexOf = function (id) {
        for (var i = 0; i < this.tracks.length; i++) {
            if (this.tracks[i].id === id) {
                return i;
            }
        }
        return -1;
    };

    /** Makes a track current without it counting as a step - a click on a row, a deep link */
    Queue.prototype.select = function (index) {
        if (index < 0 || index >= this.tracks.length) {
            return false;
        }
        this.current = index;
        return true;
    };

    /** A track other than the current one, when there is another */
    Queue.prototype.randomIndex = function () {
        var length = this.tracks.length;
        if (length <= 1) {
            return 0;
        }
        var index;
        do {
            index = Math.floor(this.random() * length);
        } while (index === this.current);
        return index;
    };

    /** Steps forward - the row below, round to the top, or anywhere else when shuffling */
    Queue.prototype.next = function () {
        if (!this.tracks.length) {
            return -1;
        }
        var index = this.shuffle
            ? this.randomIndex()
            : (this.current === -1 ? 0 : (this.current + 1) % this.tracks.length);
        if (this.current !== -1) {
            this.history.push(this.current);
        }
        this.current = index;
        return index;
    };

    /**
     * Steps back, the way a music player does
     *
     * Past the first few seconds, Previous means *this track again*: the answer is
     * `Queue.RESTART` and the queue does not move. Otherwise back through what was played -
     * which is what makes it work in a shuffled order - and then simply the row above.
     */
    Queue.prototype.previous = function (elapsed) {
        if (!this.tracks.length) {
            return -1;
        }
        if (this.current !== -1 && elapsed > Queue.RESTART_AFTER) {
            return Queue.RESTART;
        }
        if (this.history.length) {
            this.current = this.history.pop();
            return this.current;
        }
        this.current = this.current <= 0 ? this.tracks.length - 1 : this.current - 1;
        return this.current;
    };

    /**
     * Puts the tracks in another order, keeping hold of the one that is playing
     *
     * The current track is found again by id, since its index has moved. The history is dropped:
     * it is a list of indexes, and they no longer name the tracks they did.
     */
    Queue.prototype.sort = function (name) {
        var compare = MusicList.comparators[name];
        if (!compare) {
            return false;
        }
        var playing = this.track();
        // the index breaks a tie, so tracks with the same date keep the order they were given
        // in - and sorting twice is the same as sorting once
        this.tracks = this.tracks
            .map(function (track, index) { return {track: track, index: index}; })
            .sort(function (a, b) { return compare(a.track, b.track) || a.index - b.index; })
            .map(function (entry) { return entry.track; });
        this.current = playing ? this.indexOf(playing.id) : -1;
        this.history = [];
        return true;
    };

    /** Previous restarts the track after this many seconds of it */
    Queue.RESTART_AFTER = 3;
    Queue.RESTART = -2;

    MusicList.Queue = Queue;

    // ------------------------------------------------------------------------------------------
    // the player
    // ------------------------------------------------------------------------------------------

    var ICONS = {
        play: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>',
        pause: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="5" width="4" height="14"/>' +
            '<rect x="14" y="5" width="4" height="14"/></svg>'
    };

    /** How much of the spectrum is drawn: the top of it is near silent in most music, and flat */
    var VISUALIZER_FRACTION = 0.6;

    /** Every player on the page, so that starting one can pause the rest */
    var players = [];

    /**
     * iOS only plays audio routed through Web Audio while the screen is on, which would end
     * playback at the lock screen. So there the Web Audio graph is skipped and the visualizer
     * draws bars that move with the time rather than with the sound.
     */
    function isIOS() {
        var nav = global.navigator || {};
        return /iPad|iPhone|iPod/.test(nav.userAgent || '') ||
            (nav.platform === 'MacIntel' && nav.maxTouchPoints > 1);
    }

    /**
     * Binds one player
     *
     * @param {Element} root  the `[data-music-list]` element
     * @param {Object} [link]  what the address asked for, from `MusicList.deepLink()`. Its
     *                         `track` is emptied by the player that takes it, so of two players
     *                         listing the same track only the first starts
     * @return {Object|null} the player, or null when there is nothing to play
     */
    MusicList.create = function (root, link) {
        var document = global.document;
        var find = function (name) { return root.querySelector('[data-ml-' + name + ']'); };
        var audio = find('audio');
        var list = find('tracks');
        if (!audio || !list || root.dataset.musicListBound) {
            return null;
        }
        root.dataset.musicListBound = '1';

        var els = {
            backdrop: find('backdrop'), cover: find('cover'), title: find('title'), album: find('album'),
            visualizer: find('visualizer'), sort: find('sort'), play: find('play'), prev: find('prev'),
            next: find('next'), shuffle: find('shuffle'), seek: find('seek'), current: find('current'),
            duration: find('duration'), toast: find('toast')
        };
        var queue = new Queue(
            Array.prototype.map.call(list.querySelectorAll('[data-ml-track]'), MusicList.readTrack)
                .filter(function (track) { return track.id !== '' && track.url !== ''; })
        );
        if (!queue.tracks.length) {
            return null;
        }

        var baseTitle = document.title;
        var pendingSeek = null;     // seconds to jump to once the new track's length is known
        var announced = false;      // `music-list:play` is sent once per selected track
        var audioContext = null;
        var analyser = null;
        var frequencies = null;
        var frame = 0;
        var barColor = '#6ea8fe';
        var wakeLock = null;
        var toastTimer = 0;

        // --- covers ---

        /** Puts a track's cover on an image, and the drawn one if it will not load */
        function showCover(image, track, large) {
            var fallback = MusicList.placeholderFor(track.title);
            var source = (large && track.coverLarge) || track.cover || fallback;
            image.onerror = function () {
                image.onerror = null;
                image.src = fallback;
            };
            image.src = source;
            return source;
        }

        // --- the rows ---

        function highlight() {
            var playing = queue.track();
            queue.tracks.forEach(function (track) {
                var active = track === playing;
                track.element.classList.toggle('is-active', active);
                if (active) {
                    track.element.setAttribute('aria-current', 'true');
                } else {
                    track.element.removeAttribute('aria-current');
                }
            });
        }

        /**
         * Keeps the playing row in sight - when the player is, and the row is not
         *
         * The player is a part of a page, and the page is what scrolls. So two things leave it
         * alone: a row already wholly on screen, and **a player that is not on screen at all** -
         * somebody reading the rest of the page while the music plays has not asked to be taken
         * back to the list every time a track ends.
         */
        function reveal() {
            var playing = queue.track();
            if (!playing || !playing.element.getBoundingClientRect) {
                return;
            }
            var height = global.innerHeight || document.documentElement.clientHeight;
            var player = root.getBoundingClientRect();
            if (player.bottom <= 0 || player.top >= height) {
                return;
            }
            var row = playing.element.getBoundingClientRect();
            if (row.top < 0 || row.bottom > height) {
                playing.element.scrollIntoView({block: 'center'});
            }
        }

        function activate(track) {
            var index = queue.tracks.indexOf(track);
            // the row that is playing is a pause button; any other row is a track to start
            if (index === queue.current) {
                toggle();
            } else {
                select(index, true);
            }
        }

        function bindRow(track) {
            var row = track.element;
            var thumb = row.querySelector('[data-ml-thumb]');
            var share = row.querySelector('[data-ml-share]');
            var download = row.querySelector('[data-ml-download]');
            row.tabIndex = 0;
            row.setAttribute('role', 'button');
            if (thumb && !thumb.getAttribute('src')) {
                thumb.src = MusicList.placeholderFor(track.title);
            }
            row.addEventListener('click', function () { activate(track); });
            row.addEventListener('keydown', function (event) {
                // only the row's own keys: Enter on the share button inside it is the button's
                if (event.target === row && (event.key === 'Enter' || event.key === ' ')) {
                    event.preventDefault();
                    activate(track);
                }
            });
            if (share) {
                share.hidden = false;
                share.addEventListener('click', function (event) {
                    event.stopPropagation();
                    shareTrack(track);
                });
            }
            if (download) {
                download.addEventListener('click', function (event) { event.stopPropagation(); });
            }
        }

        // --- playing ---

        function select(index, autoplay, startAt) {
            if (!queue.select(index)) {
                return;
            }
            var track = queue.track();
            pendingSeek = startAt > 0 ? startAt : null;
            announced = false;
            audio.src = track.url;
            els.title.textContent = track.title;
            els.album.textContent = track.album ? '— ' + track.album : '';
            els.album.hidden = !track.album;
            document.title = track.title + ' — ' + baseTitle;
            var cover = showCover(els.cover, track, true);
            els.cover.alt = track.title + ' cover';
            if (els.backdrop) {
                els.backdrop.style.backgroundImage = 'url("' + cover.replace(/"/g, '%22') + '")';
            }
            if (global.navigator && 'mediaSession' in global.navigator && global.MediaMetadata) {
                global.navigator.mediaSession.metadata = new global.MediaMetadata({
                    title: track.title,
                    album: track.album,
                    artwork: [{src: cover, sizes: '512x512'}]
                });
            }
            // The address names the track, so it can be copied as it stands. Not the position:
            // that changes every second, and Share is what captures it.
            if (global.history && global.history.replaceState) {
                global.history.replaceState(null, '', MusicList.shareUrl(global.location.href, track.id, 0));
            }
            highlight();
            reveal();
            if (autoplay) {
                play();
            }
        }

        function play() {
            players.forEach(function (other) {
                if (other.audio !== audio) {
                    other.audio.pause();
                }
            });
            ensureAudioGraph();
            if (audioContext && audioContext.state === 'suspended') {
                audioContext.resume();
            }
            takeMediaSession();
            // a browser may refuse to start without a click - a deep link is the usual case -
            // and then the track is simply cued and waiting, which is the right thing to see
            var started = audio.play();
            if (started && started.catch) {
                started.catch(function () {});
            }
        }

        function toggle() {
            if (queue.current === -1) {
                select(0, true);
            } else if (audio.paused) {
                play();
            } else {
                audio.pause();
            }
        }

        function next() {
            var index = queue.next();
            if (index !== -1) {
                select(index, true);
            }
        }

        function previous() {
            var index = queue.previous(audio.currentTime);
            if (index === Queue.RESTART) {
                audio.currentTime = 0;
            } else if (index !== -1) {
                select(index, true);
            }
        }

        function toggleShuffle() {
            queue.shuffle = !queue.shuffle;
            els.shuffle.setAttribute('aria-pressed', String(queue.shuffle));
        }

        /** Re-orders the rows to match the queue, which is what keeps Next meaning the row below */
        function sort(name) {
            if (!queue.sort(name)) {
                return;
            }
            queue.tracks.forEach(function (track) { list.appendChild(track.element); });
            highlight();
        }

        /**
         * Tells the page a track started, once per track
         *
         * A DOM event rather than a call to anybody's analytics: this plugin counts nothing and
         * loads nothing from elsewhere, and a site that wants plays counted listens for
         * `music-list:play` and decides for itself what consent that needs.
         */
        function announce() {
            var track = queue.track();
            if (announced || !track || typeof global.CustomEvent !== 'function') {
                return;
            }
            announced = true;
            root.dispatchEvent(new global.CustomEvent('music-list:play', {
                bubbles: true,
                detail: {id: track.id, title: track.title, album: track.album}
            }));
        }

        /** The lock screen's and the headset's buttons belong to whichever player started last */
        function takeMediaSession() {
            if (!global.navigator || !('mediaSession' in global.navigator)) {
                return;
            }
            var session = global.navigator.mediaSession;
            try {
                session.setActionHandler('previoustrack', previous);
                session.setActionHandler('nexttrack', next);
                session.setActionHandler('play', play);
                session.setActionHandler('pause', function () { audio.pause(); });
            } catch (ignore) {
                // an action this browser does not know is not a reason to stop playing
            }
        }

        // --- time ---

        function showProgress() {
            els.current.textContent = MusicList.formatTime(audio.currentTime);
            if (isFinite(audio.duration) && audio.duration > 0) {
                var percent = (audio.currentTime / audio.duration) * 100;
                els.seek.value = percent;
                els.seek.style.setProperty('--ml-pct', percent + '%');
            }
        }

        function seek() {
            if (isFinite(audio.duration) && audio.duration > 0) {
                audio.currentTime = (els.seek.value / 100) * audio.duration;
            }
            els.seek.style.setProperty('--ml-pct', els.seek.value + '%');
        }

        // --- sharing ---

        function shareTrack(track) {
            var playing = queue.track() === track;
            var url = MusicList.shareUrl(global.location.href, track.id, playing ? audio.currentTime : 0);
            var nav = global.navigator || {};
            var copy = function () {
                if (!nav.clipboard || !nav.clipboard.writeText) {
                    return toast('Could not copy the link');
                }
                nav.clipboard.writeText(url).then(
                    function () { toast('Link copied'); },
                    function () { toast('Could not copy the link'); }
                );
            };
            if (!nav.share) {
                return copy();
            }
            nav.share({title: track.title, url: url}).catch(function (error) {
                // closing the share sheet is an answer, not a failure
                if (!error || error.name !== 'AbortError') {
                    copy();
                }
            });
        }

        function toast(message) {
            els.toast.textContent = message;
            els.toast.classList.add('is-shown');
            global.clearTimeout(toastTimer);
            toastTimer = global.setTimeout(function () { els.toast.classList.remove('is-shown'); }, 1800);
        }

        // --- the visualizer ---

        /**
         * The Web Audio graph, built once
         *
         * A media element can be wired into one `MediaElementSource` for the whole of its life,
         * so this is made on the first play - which is also the first moment a browser allows an
         * `AudioContext` to make a sound - and kept.
         */
        function ensureAudioGraph() {
            var AudioContext = global.AudioContext || global.webkitAudioContext;
            if (audioContext || !AudioContext || isIOS()) {
                return;
            }
            try {
                audioContext = new AudioContext();
                var source = audioContext.createMediaElementSource(audio);
                analyser = audioContext.createAnalyser();
                analyser.fftSize = 128;
                analyser.smoothingTimeConstant = 0.8;
                frequencies = new Uint8Array(analyser.frequencyBinCount);
                source.connect(analyser);
                analyser.connect(audioContext.destination);   // or nothing is heard
            } catch (ignore) {
                analyser = null;   // the bars fall back to the drawn ones; the music still plays
            }
        }

        /** One level per bar, 0 to 1: the spectrum where there is one, a moving shape where not */
        function levels(playing) {
            var result;
            var i;
            if (analyser) {
                analyser.getByteFrequencyData(frequencies);
                var count = Math.max(1, Math.floor(frequencies.length * VISUALIZER_FRACTION));
                result = new Array(count);
                for (i = 0; i < count; i++) {
                    result[i] = playing ? frequencies[i] / 255 : 0;
                }
                return result;
            }
            var time = audio.currentTime;
            result = new Array(28);
            for (i = 0; i < 28; i++) {
                var wave = 0.45 + 0.35 * Math.sin(time * 4 + i * 0.5) + 0.18 * Math.sin(time * 6.7 + i * 0.9);
                result[i] = playing ? Math.min(1, Math.max(0.05, wave)) : 0;
            }
            return result;
        }

        function draw() {
            var canvas = els.visualizer;
            var context = canvas && canvas.getContext ? canvas.getContext('2d') : null;
            if (!context) {
                return;
            }
            // the backing store follows the displayed size, or the bars are soft on a dense screen
            var ratio = global.devicePixelRatio || 1;
            var width = Math.round((canvas.clientWidth || canvas.width) * ratio);
            var height = Math.round((canvas.clientHeight || canvas.height) * ratio);
            if (canvas.width !== width) { canvas.width = width; }
            if (canvas.height !== height) { canvas.height = height; }
            context.clearRect(0, 0, width, height);

            var bars = levels(!audio.paused);
            var gap = Math.max(1, Math.floor(width / bars.length / 6));
            var barWidth = (width - gap * (bars.length - 1)) / bars.length;
            context.fillStyle = barColor;
            for (var i = 0; i < bars.length; i++) {
                var barHeight = Math.max(2 * ratio, bars[i] * height);
                context.fillRect(i * (barWidth + gap), height - barHeight, barWidth, barHeight);
            }
        }

        /**
         * Draws while something is playing, and stops when nothing is
         *
         * A loop that runs for as long as the page is open costs a frame sixty times a second
         * to draw a row of flat bars. One last frame after a pause leaves them at rest.
         */
        function animate() {
            global.cancelAnimationFrame(frame);
            // read once a run and not once a frame: the bars are the accent a theme chose
            var style = global.getComputedStyle ? global.getComputedStyle(root) : null;
            barColor = (style && style.getPropertyValue('--ml-accent').trim()) || '#6ea8fe';
            var step = function () {
                draw();
                if (!audio.paused) {
                    frame = global.requestAnimationFrame(step);
                }
            };
            step();
        }

        // --- keeping the screen on ---

        function requestWakeLock() {
            var nav = global.navigator || {};
            if (!nav.wakeLock || wakeLock) {
                return;
            }
            nav.wakeLock.request('screen').then(function (lock) {
                wakeLock = lock;
                lock.addEventListener('release', function () { wakeLock = null; });
            }, function () {
                // refused - a low battery, a browser setting. The music plays on.
            });
        }

        function releaseWakeLock() {
            if (wakeLock) {
                wakeLock.release().catch(function () {});
                wakeLock = null;
            }
        }

        // --- wiring ---

        queue.tracks.forEach(bindRow);
        els.play.addEventListener('click', toggle);
        els.next.addEventListener('click', next);
        els.prev.addEventListener('click', previous);
        els.shuffle.addEventListener('click', toggleShuffle);
        els.seek.addEventListener('input', seek);
        if (els.sort) {
            els.sort.addEventListener('change', function () { sort(els.sort.value); });
        }

        audio.addEventListener('timeupdate', showProgress);
        audio.addEventListener('loadedmetadata', function () {
            els.duration.textContent = MusicList.formatTime(audio.duration);
            if (pendingSeek !== null) {
                audio.currentTime = Math.min(pendingSeek, audio.duration || pendingSeek);
                pendingSeek = null;
            }
            showProgress();
        });
        audio.addEventListener('play', function () {
            els.play.innerHTML = ICONS.pause;
            els.play.setAttribute('aria-label', 'Pause');
            root.classList.add('is-playing');
            requestWakeLock();
            announce();
            animate();
        });
        audio.addEventListener('pause', function () {
            els.play.innerHTML = ICONS.play;
            els.play.setAttribute('aria-label', 'Play');
            root.classList.remove('is-playing');
            releaseWakeLock();
        });
        audio.addEventListener('ended', next);
        // a wake lock is let go when the page is hidden, and has to be asked for again
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && !audio.paused) {
                requestWakeLock();
            }
        });

        // The rows arrive in the order the shortcode asked for; the select says the same thing.
        // Sorting once here makes that true of the queue too, whatever the server's tie-breaks.
        var initialSort = MusicList.comparators[root.dataset.sort] ? root.dataset.sort : MusicList.DEFAULT_SORT;
        if (els.sort) {
            els.sort.value = initialSort;
        }
        sort(initialSort);

        // what waited for a script, now that there is one
        ['now', 'toolbar', 'controls'].forEach(function (name) {
            var part = find(name);
            if (part) {
                part.hidden = false;
            }
        });
        root.classList.add('is-ready');
        animate();

        var player = {root: root, audio: audio, queue: queue, select: select, play: play, next: next, previous: previous};
        players.push(player);

        if (link && link.track) {
            var wanted = queue.indexOf(link.track);
            if (wanted !== -1) {
                link.track = '';   // taken: a second player listing the same track stays quiet
                select(wanted, true, link.at);
            }
        }
        return player;
    };

    /**
     * Binds every player under `scope` that is not bound yet
     *
     * Safe to call again - after a page inserted a player it did not start with, say.
     */
    MusicList.init = function (scope) {
        var document = global.document;
        var link = MusicList.deepLink(global.location ? global.location.search : '');
        var roots = (scope || document).querySelectorAll('[data-music-list]');
        Array.prototype.forEach.call(roots, function (root) {
            MusicList.create(root, link);
        });
    };

    global.MusicList = MusicList;

    if (global.document && global.document.addEventListener) {
        // the script is deferred, so the page is usually parsed by now - but not if somebody
        // loads it another way
        if (global.document.readyState === 'loading') {
            global.document.addEventListener('DOMContentLoaded', function () { MusicList.init(); });
        } else {
            MusicList.init();
        }
    }
})(typeof window !== 'undefined' ? window : globalThis);
