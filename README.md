# AcmlmBoard - Symfony port

A port of **AcmlmBoard 1.A3** (2000-2005) to Symfony 7.4 on PHP 8.2+, keeping the
original's look and everything a member could do on it.

Ported by **Xeon Productions**, developed with **Claude Code**, under the
**[LGPL-3.0-or-later](LICENSE.md)**. AcmlmBoard itself is (C) 2000-2005 Acmlm, Emuz,
Xkeeper, ||bass, Jesper and others, and was never released under any licence - see
[LICENSE.md](LICENSE.md) for what that means for the bundled artwork and data.

The URL structure and database schema are new; the behaviour is not. Where the
original was wrong in a way members could feel - drifting post counts, a search that
returned forums you could not read - it is fixed, and the difference is written down
rather than left to be discovered.

A few things are carried back from **AcmlmBoard 1.92.08**, a branch that diverged from
1.A3 rather than succeeding it - see [below](#what-came-from-19208).

**[INSTALL.md](INSTALL.md)** covers requirements, first run, serving it, cron and
importing an existing board.

---

## What is here

Everything the original had:

| | |
|---|---|
| **Forums** | categories, per-forum read/thread/reply power thresholds, local moderators, forum bans, announcements (board-wide and per-forum), mark-read tracking, favorites, trash forum |
| **Threads** | sticky, closed, admin-only lock, trash, move, post icons, polls with multi-vote, permalinks, Atom feeds |
| **Posts** | custom HTML headers, signatures and backgrounds; the `&numposts&` layout-token vocabulary; 9 post layouts; quoting; per-post edit/delete; layout blocking |
| **Members** | profiles, ratings, custom titles, a 292-picture avatar gallery, 33 colour schemes, named timezones, post radar, birthdays, member list, active users, online list, ranks |
| **Levels** | the original EXP/level curve, exactly, plus the RPG item shop with six equipment slots, its stat engine, and the status screen as a PNG |
| **Statistics** | board stats, ACS daily post rankings, and post breakdowns by forum, thread and hour of day |
| **Messaging** | private messages, user-created folders, system messages that block posting until read |
| **Moderation** | staff panel, disciplinary records with strikes, soft bans, forum bans, IP/CIDR bans, audit log, IP search |
| **Admin** | board configuration, forum and category management, moderator assignment, user administration |

Removed deliberately: the Java IRC applet (PJIRC), imood mood images, ICQ online
badges and the SamSpade/ARIN whois links. Those services no longer exist.

Not ported: `sigsize.php` (a staff tool listing members by signature byte size),
`photo.php` (a member photo gallery whose directory shipped empty), and the per-user
activity graph. None of the three was linked from anywhere on the original board.

### What came from 1.92.08

1.92.08 is a **branch, not a later release**. It added some things and dropped others:
RSS feeds, private-message folders, system messages, the board configuration page and
the credits page are all absent from it, and this port keeps those from 1.A3.

What it did have that was worth taking:

- **The wide post layout** (`postwide`), where the poster's details run across the top
  of the post instead of down the side, giving the body the full width of the table.
  That makes nine layouts rather than eight.
- **Five colour schemes** - Aceboard, Alliance, Horde, Twilight and Yoshi.

The schemes are **palettes only, and not faithful reproductions**. In 1.92.08 each was
a whole-page layout override with its own sidebars, banner images and table structure,
and 24 of the 25 image files those schemes reference are missing from that tree. What
is ported is each scheme's actual colour values applied to this board's markup: right
in tone, but not a rebuild of the fork's page structure.

Its `memberlinks.php` (a directory of members' homepages) and `minipics.php` (everyone
with a minipic, in three columns) are not ported. Both are small and self-contained,
and neither was linked from anywhere in the fork either.

---

## Notes on the port

### Post layouts are still raw HTML

That was the point of AcmlmBoard, so it is preserved. What changed is how it is made
safe.

The original's defence was a blocklist: regex out `<script`, strip `on*=` attributes
that had quoted values, and replace the literal string `"filter:"` with `"x:"`. It ran
*before* markup expansion, so anything that reassembled a tag afterwards went straight
through, and it never considered SVG, unquoted handler values, entity encoding, or
`-moz-binding`.

This port uses `symfony/html-sanitizer` with an allowlist, applied **last**, after
every producer has run. Anything not explicitly permitted is dropped. Surviving
`style` attributes are filtered again against a property allowlist
(`App\Service\Sanitizer\StyleAttributeSanitizer`), which strips CSS comments and
control characters before checking, so `expr/*x*/ession(` and `java\0script:` do not
slip past.

Raw input is stored exactly as typed and sanitised on render, so a layout the filter
rejects can always be opened and repaired - nothing is silently mangled at save time.

### Things the original got wrong that are fixed here

- **`editpost.php` had no authorisation check at all.** It loaded a post by id from
  the query string and saved whatever was submitted; the only thing stopping anyone
  was that the Edit link was not rendered for them. Every action now goes through a
  voter.
- **All moderation was GET links.** `thread.php?id=1&qmod=1&trash=1` with no token -
  an `<img>` tag in a post was enough to make any moderator who viewed it trash that
  thread. Everything state-changing is POST with a CSRF token.
- **Passwords travelled on every request.** Login set a cookie containing the password
  run through `shenc()`, a reversible byte shuffle, and every request decoded it back
  to plaintext to compare an md5. Now: a signed session and Symfony's remember-me.
- **Search built its WHERE clause by concatenation** and did not filter by forum
  visibility, so it returned restricted forums' contents to anyone who could search.
  All parameters are bound and results are always scoped to what the viewer can read.
- **IP bans matched with `INSTR('$ip', ip) = 1`** - a prefix match on the string, with
  the address interpolated unescaped. Banning `10.0.0.1` also caught `10.0.0.10`
  through `.19`, and a crafted `X-Forwarded-For` was SQL injection on every page. Bans
  are now CIDR ranges matched with `IpUtils`, against an address resolved through
  Symfony's trusted-proxy handling.
- **Counters drifted permanently.** Seven unguarded UPDATEs per post meant any failure
  or race left the board inconsistent, and deleting a post never decremented the
  author's count. All of it is now one transaction, and `app:board:recount` rebuilds
  every derived value from the rows.
- **Registration codes were predictable.** The verification code came from `rand()`
  seeded with `time()` divided by values the registrant controlled, was stored in
  cleartext, and doubled as the account's password field. Codes now come from
  `random_bytes()`, only their hash is stored, and the member chooses their own
  password at redemption.
- **`updategb()` ran on every page load**, walking the whole users table and issuing up
  to nine UPDATEs that matched ranks by `text LIKE '%=3%'`. Percentile ranks are data
  now, recalculated by the maintenance command.
- **The ACS rankings counted every post on the board**, including forums the viewer
  could not read, so the numbers leaked activity in staff-only forums. Every
  statistics query is scoped to the viewer's readable forums.

### Performance

The original index page issued somewhere north of thirty queries per request, most of
them `COUNT(*)` over full tables, plus a write to `users`, a delete-and-insert on
`guests`, and a rank recalculation - for every visitor, logged in or not. Here:

- board totals are cached for a minute
- presence and the page-view counter are written on `kernel.terminate`, after the
  response is sent, and at most once a minute per user
- the forum index, thread lists and post pages each load in a single query with the
  joins they need, instead of one query per row
- guest tracking is a single upsert rather than a DELETE plus an INSERT
- post layouts are content-addressed by hash rather than looked up with
  `WHERE text = '...'` against an unindexed TEXT column

### Additions beyond the original

- **Real timezones.** The original asked each member for their offset from board time
  as a number of hours - a question with no stable answer, since it changes twice a
  year under daylight saving, and one nobody wants to work out. Members pick a named
  IANA zone instead (`America/Chicago`), and the browser's own zone is detected and
  preselected, so most never touch the field. Every timestamp, the daily-cycle skin,
  the ACS day boundary and the hour-of-day histogram all follow the viewer's zone
  rather than the server's.

- **Passkeys.** Optional and additive: passwords keep working exactly as before. A
  member registers one per device at `/profile/passkeys` and signs in with a
  fingerprint, face, screen lock or security key. The private key never leaves the
  device, and the authenticator refuses to sign for the wrong origin - which is what
  makes a passkey unphishable, unlike any password.

  Needs a secure context: HTTPS in production, or localhost while developing. The
  board refuses to delete a member's last passkey when their account has no password,
  so it cannot lock anyone out.

- **Authenticator apps (TOTP).** Also optional. Setup shows a QR code and the key in
  typeable blocks, and **nothing about signing in changes until a code has been
  entered successfully** - so abandoning the page halfway cannot lock you out. Ten
  single-use recovery codes are issued on activation; turning it off asks for a
  current code, so somebody at your unlocked browser cannot quietly remove it.

  Seeds are encrypted at rest with AES-256-GCM under a key derived from `APP_SECRET`.
  A seed cannot be hashed - the board has to recover it to check a code - so this
  moves a database leak from "every member's second factor" to "needs the application
  secret too". Recovery codes *are* hashed; they are single-use password equivalents.

  Signing in with a passkey does not then ask for a code. A passkey is already two
  factors, and demanding the same assurance twice is how people are driven off the
  stronger method.

- **Type that scales.** The original's 13px and 10px suited a 1024x768 CRT and are
  hard to read now. Sizes are fluid between a floor and a ceiling - roughly 14/12px on
  a laptop, 18/15px on a very wide screen - with the bounds in `rem`, so they follow
  the reader's own browser font size instead of overriding it. `html { font-size:
  81.25% }` restores the original proportions exactly.

- **Nine extra colour schemes** beyond the original nineteen: five palettes carried
  over from the acmlm19208 fork, plus Matrix and a set based on editor themes
  (Dark+, Monokai, Dracula, Nord, One Dark, Solarized light and dark, GitHub Dark).

### Deliberate behaviour changes

- **Thread post icons** are chosen from a shipped set. The original stored a full URL
  and rendered it unescaped, which made the icon field a stored-XSS vector on every
  forum listing.
- **Spoilers** actually hide their content instead of rendering black-on-black, which
  was defeated by selecting the text.
- **`[code]` blocks** are lifted out before the pipeline runs and restored after, so
  markup, smileys and layout tokens inside them display literally.
- **Rank sets** repeat every 10,000 posts outside the percentile set - that quirk was
  intentional on a board where the top members had five-digit counts, and it is kept.
  One consequence: a rung set at exactly 10,000 can never be earned, because the
  modulo turns it straight back into 0.
- **CSRF tokens are session-backed**, not Symfony 7.4's stateless default, because the
  stateless scheme needs JavaScript to populate the token and this board works with
  scripting disabled.

One genuine bug in the RPG stat engine is *not* preserved: `basestat()` labelled its
cases `HP, MP, Str, Atk, Def, Shl, Lck, Int, Spd` but was indexed by an array reading
`HP, MP, Atk, Def, Int, MDf, Dex, Lck, Spd`, so several curves were applied to the
wrong stat and Lck/Int were transposed. Curves are bound to stat names here; the
numbers are otherwise identical.

---

## Layout tokens

Usable in post headers and signatures:

`&numposts&` `&numdays&` `&exp&` `&level&` `&lvlexp&` `&lvllen&` `&expdone&`
`&expnext&` `&expdone1k&` `&expnext1k&` `&expdone10k&` `&expnext10k&` `&exppct&`
`&exppct2&` `&expgain&` `&expgaintime&` `&date&` `&rank&` `&postrank&`
`&postrank10k&` `&postrank20k&` `&postrank30k&` `&5000&` `&20000&` `&30000&`

Values are frozen into a post when it is made, so old posts keep saying what they
said. Members who set *Signatures and post headers* to **auto-updating** see every
author's current layout with live values instead.

---

## Tests

```bash
php vendor/bin/phpunit
```

**647 tests, 2238 assertions.** Line coverage 70%.

### How the suite is arranged

- **`tests/Entity`, `tests/Service`** - no database, no kernel where it can be
  avoided; these run in well under a second.
- **`tests/Functional`** - the board driven over HTTP against a seeded world of seven
  members spanning every power level, restricted and staff-only forums, and open,
  closed and locked threads. `tests/Support/TestWorld.php` builds that world once per
  process, snapshots every row, and restores it by truncate-and-reinsert before each
  test. Restoring rather than sharing costs about a second per test and is worth it:
  much of what is under test is banning and moderation, and a test that bans somebody
  must not decide whether the next one passes. Truncate also resets the auto-increment
  counters, so seeded ids are stable and tests can refer to them.

`RouteSmokeTest` renders every page the board has, at every power level, plus all 33
colour schemes and all 9 post layouts. It is shallow on purpose - it asserts a page
renders at all - but that is exactly the property a port of this size most easily
breaks.

Where a test needs a code, a signature or an authenticator, it generates a real one:
TOTP codes come from the issued seed, and the passkey ceremonies are driven by a
software authenticator with a genuine ES256 key pair and hand-assembled CBOR.

### Coverage

There is no coverage driver in a stock PHP install. With PCOV or Xdebug available:

```bash
php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text
```

Coverage is a map of what has never been executed, not a score to raise. It is what
turned up the search fault below: the repository showed as covered because something
called `search()`, but nothing had ever passed it a search *term*.

The obvious remaining gap is `app:board:import-legacy`, which needs a real legacy
database to exercise. Its timezone mapping is unit-tested directly; the rest is not.

### Regression cover

Each of these is a bug that shipped, or nearly did, and now has a test standing over
it:

- **Search was entirely broken.** The DQL read `LIKE :text ESCAPE :esc`, and DQL will
  not accept a parameter after `ESCAPE` - it has to be a literal. Every search with an
  actual term raised a syntax error, and so did the admin IP lookup; the only searches
  that ever worked were empty ones. The escape character is now `=`, and the tests
  search for `%`, `_` and `=` themselves to prove they are matched literally.
- **Forum post counts drifted on every deletion.** `PostManager::delete()` decremented
  the thread's reply count in memory and then called `recount()`, which derives the
  forum totals with `SUM(threads.replies)` straight from the database - so the forum
  was recounted from the pre-deletion figure and written back one too high.
- **Choosing an avatar broke saving your profile.** The gallery stores what it serves,
  `/images/avatars/...`, while the profile form demanded a full `http(s)` URL. Every
  save then failed validation on a field the member had not touched - and because a
  rejected form is re-rendered with the submitted values, it looked saved until the
  next reload.
- **Validation failures were invisible.** The error summary rendered only the *root*
  form's errors, and nearly every failure belongs to a field, so it was empty in
  exactly the case it existed for.
- **The timezone selector could not offer the default timezone.** The intl catalogue
  has no bare `UTC`, which is what every new account starts on, so the member's own
  zone was missing from the list and saving anything moved them to `America/New_York`.
- **The percentile rank ladder had one rung instead of nine.** The fixtures used float
  array keys (`0.001 => 'Top 0.1%'`); PHP casts those to `int`, so all nine collapsed
  to key `0`.
- **Previews** render a `Post` with no database row, so its id and its thread's id are
  null. Anything building a route or consulting a voter with those throws. All four
  preview paths share one Twig macro and broke together.
- **Password constraints** are instantiated through the real container.
  `NotCompromisedPasswordValidator` throws from its *constructor* when
  `symfony/http-client` is missing, so a missing dependency surfaced as "saving your
  profile is broken" even with the password field left blank.
- **Passkey algorithms.** `Cose\Algorithm\Manager::create()` takes no arguments, so
  `create([...])` silently yields an empty manager. Registration still succeeds - a
  "none" attestation verifies no signature - and the failure only appears at the first
  sign-in, when the member has a passkey that can never work.

---

## Licence

The port is **LGPL-3.0-or-later**. Full terms in [LICENSE.md](LICENSE.md), with the
texts in [`COPYING.LESSER`](COPYING.LESSER) and [`COPYING`](COPYING).

One caveat worth reading before you publish anything: **AcmlmBoard 1.A3 itself carries
no licence** - only a copyright notice. The LGPL covers this port's own code. It does
not cover the artwork and data carried over from the original distribution, which is
most of `public/images/` plus the smiley and avatar catalogues. Those remain their
authors' property, and no licence chosen here can change that.
