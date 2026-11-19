/**
 * A test for the player, with no toolchain at all
 *
 *   node assets/music-list.test.js
 *
 * The same arrangement as dpress's own `assets/*.test.js`: a stub DOM just big enough for what
 * the script touches, and the real file evaluated against it. Two halves - the queue, which is
 * where "next", "previous", shuffle and sort are decided and which needs no page at all, and the
 * player, bound to the markup `views/player.phtml` writes.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

// --- the smallest DOM this needs ---

/** `data-cover-large` as `coverLarge`, the way a browser fills `dataset` */
function camel(name) {
    return name.replace(/-([a-z])/g, (match, letter) => letter.toUpperCase());
}

class FakeElement {
    constructor(attributes = {}, children = []) {
        this.attributes = {};
        this.dataset = {};
        this.children = [];
        this.parent = null;
        this.listeners = {};
        this.hidden = false;
        this.textContent = '';
        this.innerHTML = '';
        this.value = '';
        this.tabIndex = -1;
        this.box = {top: 10, bottom: 60};
        this.scrolled = 0;
        this.style = {
            properties: {},
            setProperty(name, value) { this.properties[name] = value; }
        };
        const classes = new Set();
        this.classList = {
            add: name => classes.add(name),
            remove: name => classes.delete(name),
            contains: name => classes.has(name),
            toggle: (name, on) => (on ? classes.add(name) : classes.delete(name))
        };
        Object.keys(attributes).forEach(name => this.setAttribute(name, attributes[name]));
        children.forEach(child => this.appendChild(child));
    }
    setAttribute(name, value) {
        this.attributes[name] = String(value);
        if (name === 'hidden') {
            this.hidden = true;
        }
        if (name.startsWith('data-')) {
            this.dataset[camel(name.slice(5))] = String(value);
        }
    }
    getAttribute(name) {
        return name in this.attributes ? this.attributes[name] : null;
    }
    removeAttribute(name) {
        delete this.attributes[name];
    }
    set src(value) { this.attributes.src = value; }
    get src() { return this.attributes.src || ''; }
    /** Moves the child here, as the real one does - which is how sorting re-orders the rows */
    appendChild(child) {
        if (child.parent) {
            child.parent.children = child.parent.children.filter(other => other !== child);
        }
        child.parent = this;
        this.children.push(child);
        return child;
    }
    /** Only `[attribute]` selectors, which is all the script writes */
    querySelectorAll(selector) {
        const attribute = selector.slice(1, -1);
        const found = [];
        const walk = element => element.children.forEach(child => {
            if (attribute in child.attributes) {
                found.push(child);
            }
            walk(child);
        });
        walk(this);
        return found;
    }
    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }
    addEventListener(name, listener) {
        (this.listeners[name] = this.listeners[name] || []).push(listener);
    }
    /** Fires a listener and lets the event bubble, so a click on a button reaches its row */
    dispatchEvent(event) {
        event.target = event.target || this;
        let element = this;
        while (element && !event.stopped) {
            (element.listeners[event.type] || []).forEach(listener => listener(event));
            element = event.bubbles ? element.parent : null;
        }
        return true;
    }
    click() {
        this.dispatchEvent(new FakeEvent('click', {bubbles: true}));
    }
    getBoundingClientRect() { return this.box; }
    scrollIntoView() { this.scrolled++; }
    getContext() { return null; }   // no canvas: the visualizer draws nothing and says nothing
}

class FakeEvent {
    constructor(type, options = {}) {
        this.type = type;
        this.bubbles = !!options.bubbles;
        this.detail = options.detail;
        this.stopped = false;
    }
    stopPropagation() { this.stopped = true; }
    preventDefault() {}
}

/** An `<audio>` that "plays": `play()` and `pause()` flip the flag and fire the event */
class FakeAudio extends FakeElement {
    constructor() {
        super({'data-ml-audio': ''});
        this.paused = true;
        this.currentTime = 0;
        this.duration = NaN;
    }
    play() {
        this.paused = false;
        this.dispatchEvent(new FakeEvent('play'));
        return Promise.resolve();
    }
    pause() {
        if (!this.paused) {
            this.paused = true;
            this.dispatchEvent(new FakeEvent('pause'));
        }
    }
}

global.window = global;
global.location = {href: 'https://example.net/music', search: ''};
global.history = {
    replaceState(state, title, url) { global.location.href = url; }
};
global.document = {
    title: 'Music',
    readyState: 'complete',
    visibilityState: 'visible',
    documentElement: {clientHeight: 800},
    addEventListener() {},
    querySelectorAll() { return []; }
};
global.innerHeight = 800;
global.CustomEvent = FakeEvent;
global.requestAnimationFrame = () => 0;
global.cancelAnimationFrame = () => {};

eval(fs.readFileSync(path.join(__dirname, 'music-list.js'), 'utf8'));

const MusicList = global.MusicList;
const Queue = MusicList.Queue;

// --- fixtures ---

/** Three tracks, given newest first - the order the server renders by default */
const TRACKS = [
    {id: 'run', title: 'Run', released: '2026-05-03T10:00:00+00:00', album: ''},
    {id: 'aliens', title: 'Aliens', released: '2026-04-01T10:00:00+00:00', album: 'CoolFox OST'},
    {id: 'loki', title: 'Loki', released: '2026-03-01T10:00:00+00:00', album: ''}
];

function row(track) {
    return new FakeElement({
        'data-ml-track': '', 'data-id': track.id, 'data-title': track.title,
        'data-url': track.url === undefined ? '/uploads/' + track.id + '.mp3' : track.url,
        'data-cover': track.cover || '', 'data-cover-large': track.coverLarge || '',
        'data-album': track.album || '', 'data-released': track.released || ''
    }, [
        new FakeElement({'data-ml-thumb': ''}),
        new FakeElement({'data-ml-share': '', hidden: ''}),
        new FakeElement({'data-ml-download': ''})
    ]);
}

/** The markup of `views/player.phtml`, reduced to the elements the script looks for */
function playerMarkup(tracks = TRACKS, sort = 'created-desc') {
    const part = name => new FakeElement({['data-ml-' + name]: ''});
    return new FakeElement({'data-music-list': '', 'data-sort': sort}, [
        part('backdrop'),
        new FakeElement({'data-ml-now': '', hidden: ''}, [part('cover'), part('title'), part('album'), part('visualizer')]),
        new FakeElement({'data-ml-toolbar': '', hidden: ''}, [part('sort')]),
        new FakeElement({'data-ml-tracks': ''}, tracks.map(row)),
        new FakeElement({'data-ml-controls': '', hidden: ''}, [
            part('prev'), part('play'), part('next'), part('shuffle'), part('seek'), part('current'), part('duration')
        ]),
        part('toast'),
        new FakeAudio()
    ]);
}

function order(root) {
    return root.querySelector('[data-ml-tracks]').children.map(element => element.dataset.id);
}

function queueOf(ids, random) {
    return new Queue(ids.map((id, index) => ({id: id, title: id, released: 100 - index})), random);
}

const tests = {

    // --- the helpers ---

    'a time is minutes and two digits of seconds'() {
        assert.strictEqual(MusicList.formatTime(0), '0:00');
        assert.strictEqual(MusicList.formatTime(75.9), '1:15');
        assert.strictEqual(MusicList.formatTime(600), '10:00');
    },

    // the duration is NaN until the metadata loads, and the slider must not print it
    'a time that is not one yet is zero'() {
        assert.strictEqual(MusicList.formatTime(NaN), '0:00');
        assert.strictEqual(MusicList.formatTime(-4), '0:00');
        assert.strictEqual(MusicList.formatTime(Infinity), '0:00');
    },

    'a track keeps the same placeholder from one visit to the next'() {
        assert.strictEqual(MusicList.placeholderFor('AREA 67'), MusicList.placeholderFor('AREA 67'));
        assert.notStrictEqual(MusicList.placeholderFor('AREA 67'), MusicList.placeholderFor('Loki'));
        assert.ok(MusicList.placeholderFor('x').startsWith('data:image/svg+xml'));
    },

    'a share link is the page with the track on it'() {
        assert.strictEqual(
            MusicList.shareUrl('https://example.net/music', 'area-67', 0),
            'https://example.net/music?track=area-67'
        );
    },

    'sharing the playing track carries where it is, in whole seconds'() {
        assert.strictEqual(
            MusicList.shareUrl('https://example.net/music', 'run', 42.8),
            'https://example.net/music?track=run&t=42'
        );
    },

    // without URL rewriting the page itself is `?route=/music`, and losing it is losing the page
    'a share link keeps what the address already carried'() {
        const url = MusicList.shareUrl('https://example.net/index.php?route=/music&track=old&t=9#top', 'run', 0);
        assert.strictEqual(url, 'https://example.net/index.php?route=%2Fmusic&track=run');
    },

    'a deep link is read out of the address'() {
        assert.deepStrictEqual(MusicList.deepLink('?track=run&t=42'), {track: 'run', at: 42});
        assert.deepStrictEqual(MusicList.deepLink('?track=run&t=soon'), {track: 'run', at: 0});
        assert.deepStrictEqual(MusicList.deepLink(''), {track: '', at: 0});
    },

    'a row is read into a track'() {
        const track = MusicList.readTrack(row({
            id: 'run', title: 'Run', cover: '/c-thumb.jpg', coverLarge: '/c-medium.jpg',
            album: 'OST', released: '2026-05-03T10:00:00+00:00'
        }));
        assert.strictEqual(track.id, 'run');
        assert.strictEqual(track.url, '/uploads/run.mp3');
        assert.strictEqual(track.coverLarge, '/c-medium.jpg');
        assert.strictEqual(track.album, 'OST');
        assert.strictEqual(track.released, Date.parse('2026-05-03T10:00:00+00:00'));
    },

    'a date nobody can read sorts as the oldest'() {
        assert.strictEqual(MusicList.readTrack(row({id: 'x', title: 'X', released: 'someday'})).released, 0);
    },

    // --- the queue ---

    'next is the row below, and the first row when nothing is playing'() {
        const queue = queueOf(['a', 'b', 'c']);
        assert.strictEqual(queue.next(), 0);
        assert.strictEqual(queue.next(), 1);
    },

    'next goes round to the top'() {
        const queue = queueOf(['a', 'b', 'c']);
        queue.select(2);
        assert.strictEqual(queue.next(), 0);
    },

    'previous is the row above, and round to the bottom'() {
        const queue = queueOf(['a', 'b', 'c']);
        queue.select(1);
        assert.strictEqual(queue.previous(0), 0);
        assert.strictEqual(queue.previous(0), 2);
    },

    // what every music player does: Previous a few seconds in means "this one again"
    'previous restarts a track that is under way'() {
        const queue = queueOf(['a', 'b', 'c']);
        queue.select(1);
        assert.strictEqual(queue.previous(12), Queue.RESTART);
        assert.strictEqual(queue.current, 1, 'and the queue has not moved');
    },

    'shuffle never picks the track that is playing'() {
        // a random source stuck on the current index, then moving on
        const answers = [0.4, 0.4, 0.9];
        const queue = queueOf(['a', 'b', 'c'], () => answers.shift());
        queue.select(1);
        queue.shuffle = true;
        assert.strictEqual(queue.next(), 2);
    },

    'previous goes back through a shuffled order'() {
        const answers = [0.9, 0.1];
        const queue = queueOf(['a', 'b', 'c'], () => answers.shift());
        queue.select(1);
        queue.shuffle = true;
        queue.next();   // to 2
        queue.next();   // to 0
        assert.strictEqual(queue.previous(0), 2);
        assert.strictEqual(queue.previous(0), 1);
    },

    'shuffling a list of one plays the one'() {
        const queue = queueOf(['a']);
        queue.shuffle = true;
        assert.strictEqual(queue.next(), 0);
        assert.strictEqual(queue.next(), 0);
    },

    'an empty queue has no next and no previous'() {
        const queue = queueOf([]);
        assert.strictEqual(queue.next(), -1);
        assert.strictEqual(queue.previous(0), -1);
    },

    'sorting keeps hold of the track that is playing'() {
        const queue = new Queue([
            {id: 'b', title: 'Beta', released: 3},
            {id: 'c', title: 'Gamma', released: 2},
            {id: 'a', title: 'Alpha', released: 1}
        ]);
        queue.select(1);
        queue.sort('title-asc');
        assert.deepStrictEqual(queue.tracks.map(track => track.id), ['a', 'b', 'c']);
        assert.strictEqual(queue.track().id, 'c');
    },

    // the history is indexes, and after a sort they name other tracks
    'sorting forgets the way back'() {
        const queue = queueOf(['a', 'b', 'c']);
        queue.next();
        queue.next();
        queue.sort('created-asc');
        assert.deepStrictEqual(queue.history, []);
    },

    // tracks imported together share a moment; they must not swap places on every sort
    'tracks with the same date keep the order they were given in'() {
        const queue = new Queue([
            {id: 'first', title: 'x', released: 5},
            {id: 'second', title: 'x', released: 5},
            {id: 'third', title: 'x', released: 5}
        ]);
        queue.sort('created-desc');
        queue.sort('created-desc');
        assert.deepStrictEqual(queue.tracks.map(track => track.id), ['first', 'second', 'third']);
    },

    'an order nobody knows changes nothing'() {
        const queue = queueOf(['a', 'b']);
        assert.strictEqual(queue.sort('loudest'), false);
        assert.deepStrictEqual(queue.tracks.map(track => track.id), ['a', 'b']);
    },

    'titles sort without minding their case'() {
        const queue = new Queue([
            {id: 'z', title: 'zebra', released: 0},
            {id: 'a', title: 'Alpha', released: 0},
            {id: 'm', title: 'mid', released: 0}
        ]);
        queue.sort('title-desc');
        assert.deepStrictEqual(queue.tracks.map(track => track.id), ['z', 'm', 'a']);
    },

    // --- the player ---

    'binding shows what waited for a script'() {
        const root = playerMarkup();
        assert.ok(MusicList.create(root));
        ['now', 'toolbar', 'controls'].forEach(name => {
            assert.strictEqual(root.querySelector('[data-ml-' + name + ']').hidden, false, name);
        });
        assert.ok(root.classList.contains('is-ready'));
        const first = root.querySelector('[data-ml-track]');
        assert.strictEqual(first.getAttribute('role'), 'button');
        assert.strictEqual(first.tabIndex, 0);
        assert.strictEqual(first.querySelector('[data-ml-share]').hidden, false);
    },

    'a player is bound once'() {
        const root = playerMarkup();
        assert.ok(MusicList.create(root));
        assert.strictEqual(MusicList.create(root), null);
    },

    'a list with nothing in it is not a player'() {
        const root = playerMarkup([]);
        assert.strictEqual(MusicList.create(root), null);
        assert.strictEqual(root.querySelector('[data-ml-controls]').hidden, true);
    },

    // a row the server could not give a file for must not become a button that plays nothing
    'a row without a file is left out of the queue'() {
        const player = MusicList.create(playerMarkup([TRACKS[0], {id: 'ghost', title: 'Ghost', url: ''}]));
        assert.deepStrictEqual(player.queue.tracks.map(track => track.id), ['run']);
    },

    'a track without a cover gets a drawn one'() {
        const root = playerMarkup();
        MusicList.create(root);
        const thumb = root.querySelector('[data-ml-thumb]');
        assert.ok(thumb.src.startsWith('data:image/svg+xml'));
    },

    'a cover the server gave is left alone'() {
        const root = playerMarkup([{id: 'run', title: 'Run', cover: '/run-thumb.jpg'}]);
        root.querySelector('[data-ml-thumb]').setAttribute('src', '/run-thumb.jpg');
        MusicList.create(root);
        assert.strictEqual(root.querySelector('[data-ml-thumb]').src, '/run-thumb.jpg');
    },

    'clicking a row plays it'() {
        global.location.href = 'https://example.net/music';
        global.document.title = 'Music';
        const root = playerMarkup();
        MusicList.create(root);
        const rows = root.querySelectorAll('[data-ml-track]');
        rows[1].click();

        const audio = root.querySelector('[data-ml-audio]');
        assert.strictEqual(audio.src, '/uploads/aliens.mp3');
        assert.strictEqual(audio.paused, false);
        assert.strictEqual(root.querySelector('[data-ml-title]').textContent, 'Aliens');
        assert.strictEqual(root.querySelector('[data-ml-album]').hidden, false);
        assert.ok(rows[1].classList.contains('is-active'));
        assert.ok(!rows[0].classList.contains('is-active'));
        assert.strictEqual(root.querySelector('[data-ml-play]').getAttribute('aria-label'), 'Pause');
        assert.strictEqual(global.location.href, 'https://example.net/music?track=aliens');
        assert.strictEqual(global.document.title, 'Aliens — Music');
    },

    'clicking the row that is playing pauses it'() {
        const root = playerMarkup();
        MusicList.create(root);
        const first = root.querySelector('[data-ml-track]');
        first.click();
        first.click();
        const audio = root.querySelector('[data-ml-audio]');
        assert.strictEqual(audio.paused, true);
        assert.strictEqual(audio.src, '/uploads/run.mp3', 'paused, not restarted');
        assert.strictEqual(root.querySelector('[data-ml-play]').getAttribute('aria-label'), 'Play');
    },

    // the share button is inside the row; a click on it must not also start the track
    'sharing a row does not play it'() {
        const root = playerMarkup();
        MusicList.create(root);
        // nothing here can share or copy, so the click ends in a toast - which is fine: what
        // is being asked is where the click went, not whether the link was copied
        root.querySelector('[data-ml-share]').click();
        assert.strictEqual(root.querySelector('[data-ml-audio]').paused, true);
        assert.strictEqual(root.querySelector('[data-ml-audio]').src, '');
    },

    'play with nothing chosen starts the first row'() {
        const root = playerMarkup();
        MusicList.create(root);
        root.querySelector('[data-ml-play]').click();
        assert.strictEqual(root.querySelector('[data-ml-audio]').src, '/uploads/run.mp3');
    },

    'next follows the order on the screen'() {
        const root = playerMarkup();
        MusicList.create(root);
        root.querySelector('[data-ml-play]').click();
        root.querySelector('[data-ml-next]').click();
        assert.strictEqual(root.querySelector('[data-ml-audio]').src, '/uploads/aliens.mp3');
    },

    'a track that ends is followed by the next'() {
        const root = playerMarkup();
        MusicList.create(root);
        root.querySelector('[data-ml-play]').click();
        const audio = root.querySelector('[data-ml-audio]');
        audio.dispatchEvent(new FakeEvent('ended'));
        assert.strictEqual(audio.src, '/uploads/aliens.mp3');
    },

    'the select re-orders the rows, and next follows the new order'() {
        const root = playerMarkup();
        MusicList.create(root);
        root.querySelectorAll('[data-ml-track]')[0].click();   // Run
        const select = root.querySelector('[data-ml-sort]');
        select.value = 'title-asc';
        select.dispatchEvent(new FakeEvent('change'));

        assert.deepStrictEqual(order(root), ['aliens', 'loki', 'run']);
        assert.ok(root.querySelectorAll('[data-ml-track]')[2].classList.contains('is-active'), 'Run is still the one playing');
        root.querySelector('[data-ml-next]').click();
        assert.strictEqual(root.querySelector('[data-ml-audio]').src, '/uploads/aliens.mp3', 'round to the top of the new order');
    },

    'the list starts in the order the shortcode asked for'() {
        const root = playerMarkup(TRACKS, 'created-asc');
        MusicList.create(root);
        assert.deepStrictEqual(order(root), ['loki', 'aliens', 'run']);
        assert.strictEqual(root.querySelector('[data-ml-sort]').value, 'created-asc');
    },

    'shuffle says whether it is on'() {
        const root = playerMarkup();
        const player = MusicList.create(root);
        const button = root.querySelector('[data-ml-shuffle]');
        button.click();
        assert.strictEqual(button.getAttribute('aria-pressed'), 'true');
        assert.strictEqual(player.queue.shuffle, true);
        button.click();
        assert.strictEqual(button.getAttribute('aria-pressed'), 'false');
    },

    'a deep link cues its track, and seeks once the length is known'() {
        const root = playerMarkup();
        MusicList.create(root, {track: 'loki', at: 42});
        const audio = root.querySelector('[data-ml-audio]');
        assert.strictEqual(audio.src, '/uploads/loki.mp3');
        assert.strictEqual(audio.currentTime, 0, 'not before the metadata');
        audio.duration = 180;
        audio.dispatchEvent(new FakeEvent('loadedmetadata'));
        assert.strictEqual(audio.currentTime, 42);
        assert.strictEqual(root.querySelector('[data-ml-duration]').textContent, '3:00');
    },

    'a deep link past the end of the track stops at the end'() {
        const root = playerMarkup();
        MusicList.create(root, {track: 'loki', at: 999});
        const audio = root.querySelector('[data-ml-audio]');
        audio.duration = 180;
        audio.dispatchEvent(new FakeEvent('loadedmetadata'));
        assert.strictEqual(audio.currentTime, 180);
    },

    'a deep link to a track that is not here starts nothing'() {
        const root = playerMarkup();
        const link = {track: 'gone', at: 0};
        MusicList.create(root, link);
        assert.strictEqual(root.querySelector('[data-ml-audio]').src, '');
        assert.strictEqual(link.track, 'gone', 'and is left for a player that does have it');
    },

    // two players listing the same track, one link: one of them plays
    'only the first player takes a deep link'() {
        const link = {track: 'run', at: 0};
        const first = playerMarkup();
        const second = playerMarkup();
        MusicList.create(first, link);
        MusicList.create(second, link);
        assert.strictEqual(first.querySelector('[data-ml-audio]').paused, false);
        assert.strictEqual(second.querySelector('[data-ml-audio]').paused, true);
    },

    'starting one player pauses another'() {
        const first = playerMarkup();
        const second = playerMarkup();
        MusicList.create(first);
        MusicList.create(second);
        first.querySelector('[data-ml-play]').click();
        second.querySelector('[data-ml-play]').click();
        assert.strictEqual(first.querySelector('[data-ml-audio]').paused, true);
        assert.strictEqual(second.querySelector('[data-ml-audio]').paused, false);
    },

    // what an analytics script listens for; resuming after a pause is not a second play
    'a play is announced once per track'() {
        const root = playerMarkup();
        MusicList.create(root);
        const plays = [];
        root.addEventListener('music-list:play', event => plays.push(event.detail.id));
        const button = root.querySelector('[data-ml-play]');
        button.click();   // play
        button.click();   // pause
        button.click();   // resume
        root.querySelector('[data-ml-next]').click();
        assert.deepStrictEqual(plays, ['run', 'aliens']);
    },

    'the slider follows the track'() {
        const root = playerMarkup();
        MusicList.create(root);
        root.querySelector('[data-ml-play]').click();
        const audio = root.querySelector('[data-ml-audio]');
        audio.duration = 200;
        audio.currentTime = 50;
        audio.dispatchEvent(new FakeEvent('timeupdate'));
        const slider = root.querySelector('[data-ml-seek]');
        assert.strictEqual(slider.value, 25);
        assert.strictEqual(slider.style.properties['--ml-pct'], '25%');
        assert.strictEqual(root.querySelector('[data-ml-current]').textContent, '0:50');
    },

    'dragging the slider moves the track'() {
        const root = playerMarkup();
        MusicList.create(root);
        root.querySelector('[data-ml-play]').click();
        const audio = root.querySelector('[data-ml-audio]');
        audio.duration = 200;
        const slider = root.querySelector('[data-ml-seek]');
        slider.value = 50;
        slider.dispatchEvent(new FakeEvent('input'));
        assert.strictEqual(audio.currentTime, 100);
    },

    // somebody reading further down the page is not dragged back to the list at every track
    'a player that is off the screen does not scroll the page'() {
        const root = playerMarkup();
        root.box = {top: -3000, bottom: -200};
        MusicList.create(root);
        const rows = root.querySelectorAll('[data-ml-track]');
        rows.forEach(element => { element.box = {top: -900, bottom: -850}; });
        rows[0].click();
        assert.strictEqual(rows[0].scrolled, 0);
    },

    'a playing row that is out of sight is brought back'() {
        const root = playerMarkup();
        root.box = {top: -500, bottom: 700};
        MusicList.create(root);
        const rows = root.querySelectorAll('[data-ml-track]');
        rows[2].box = {top: 900, bottom: 960};
        rows[2].click();
        assert.strictEqual(rows[2].scrolled, 1);
        rows[0].click();   // wholly on screen already
        assert.strictEqual(rows[0].scrolled, 0);
    }
};

// --- runner ---

let failed = 0;
Object.keys(tests).forEach(name => {
    try {
        tests[name]();
        console.log('  ok  ' + name);
    } catch (error) {
        failed++;
        console.log('  FAIL  ' + name);
        console.log('        ' + error.message);
    }
});
const count = Object.keys(tests).length;
console.log(failed ? `\n${failed} of ${count} failed` : `\nOK (${count} tests)`);
process.exit(failed ? 1 : 0);
