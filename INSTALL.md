# Installing

## Requirements

| | |
|---|---|
| **PHP** | 8.2 or newer |
| **Extensions** | `ctype`, `gd`, `iconv`, `json`, `openssl`, `pdo_mysql` |
| **Database** | MySQL 5.7+ or MariaDB 10.3+ |
| **Composer** | 2.x |

`gd` renders the RPG status screen and the QR code for authenticator setup; `openssl`
encrypts TOTP secrets at rest. Both are declared in `composer.json`, so
`composer install` refuses rather than letting the board fail at runtime.

No JavaScript build step. There is no bundler, no `node_modules`, and the two scripts
the board ships are plain files under `public/js/`.

---

## First run

```bash
composer install

# Point DATABASE_URL at your database in .env.local, then:
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load

ADMIN_PASSWORD='...' php bin/console app:board:create-admin YourName you@example.com

symfony serve          # or: php -S 127.0.0.1:8000 -t public
```

`doctrine:fixtures:load` seeds the reference data a working board needs: 33 colour
schemes, 9 post layouts, three rank ladders, the shop catalogue, the 292-picture
avatar gallery and a starter forum structure.

The first account created is the board owner. `ADMIN_PASSWORD` is read from the
environment rather than passed as an argument so it stays out of your shell history
and out of the process list; leave it unset and the command prompts instead.

There is no self-service password reset, by design. If you are locked out:

```bash
php bin/console app:board:reset-password YourName
```

---

## Configuration

`.env.local` (never commit it):

| | |
|---|---|
| `DATABASE_URL` | connection string |
| `APP_SECRET` | signs sessions and CSRF tokens, **and derives the key TOTP secrets are encrypted with** |
| `BOARD_NAME`, `BOARD_URL` | board identity |
| `MAILER_DSN` | needed for registration verification emails |
| `TRUSTED_PROXIES` | see below |

Everything else - registration policy, power thresholds, the trash forum, whether
passkeys and authenticator apps are offered - lives in the `board_config` table and is
edited at `/admin/config`.

> **Changing `APP_SECRET` invalidates every stored TOTP secret.** Members with an
> authenticator app would have to set it up again, using a recovery code to get in.
> Sessions and CSRF tokens already depend on it in the same way.

### Trusted proxies

Leave `TRUSTED_PROXIES` empty unless the board really is behind a reverse proxy.
Setting it when there is no proxy lets any client spoof `X-Forwarded-For`, which
defeats IP bans and puts attacker-controlled values in the audit log.

Behind a proxy, set it to the proxy's address, or `REMOTE_ADDR` if the proxy is the
only thing that can reach PHP.

---

## Serving it

The board is a normal Symfony application: point a virtual host at `public/` and send
everything that is not a real file to `public/index.php`.

Two things matter regardless of which server you use:

- **The document root is `public/` and nothing above it.** `config/`, `src/`, `var/`,
  `vendor/` and `.env` must not be reachable over HTTP.
- **`index.php` should be the only PHP permitted to execute.** Nothing else under
  `public/` needs to run.

### Apache

```apache
<VirtualHost *:80>
    ServerName board.example.com
    DocumentRoot /srv/acmlmboard/public

    <Directory /srv/acmlmboard/public>
        Require all granted
        AllowOverride None
        Options -Indexes -Includes -ExecCGI +FollowSymLinks

        # Front-controller routing without .htaccess or a rewrite block.
        FallbackResource /index.php
    </Directory>

    # Only the front controller may execute.
    <FilesMatch "\.(php|phtml|phar)$">
        Require all denied
    </FilesMatch>
    <Files "index.php">
        Require all granted
    </Files>

    # Dotfiles are never content.
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>
</VirtualHost>
```

`AllowOverride None` is deliberate: it means Apache does not stat every directory
looking for `.htaccess` on each request, and the rules above cannot be weakened by a
file dropped into the tree later.

### nginx

```nginx
server {
    server_name board.example.com;
    root /srv/acmlmboard/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    # Any other PHP is a 404, not something to execute.
    location ~ \.php$ {
        return 404;
    }

    location ~ /\. {
        deny all;
    }
}
```

### Production mode

```bash
# .env.local
APP_ENV=prod
APP_DEBUG=0
```

```bash
composer install --no-dev --optimize-autoloader
php bin/console cache:clear
php bin/console cache:warmup
```

`var/` must be writable by the web server user.

### HTTPS

Passkeys need a secure context. Over plain HTTP on anything other than `localhost`,
`navigator.credentials` does not exist at all and the passkey controls stay hidden -
the board still works, but that feature is unavailable. Authenticator apps and
passwords are unaffected.

---

## Scheduled jobs

```cron
*/5 * * * *  php /srv/acmlmboard/bin/console app:board:maintenance
0 4 * * *    php /srv/acmlmboard/bin/console app:board:recount
```

`app:board:maintenance` does what the original did on *every page load*: expiring
bans, recalculating percentile ranks, writing the daily statistics row and pruning the
guest table. `app:board:recount` rebuilds the denormalised counters from the actual
rows; on a healthy board it finds nothing to change, which is what makes it useful as
a check.

---

## Updating the rank ladders

The rank ladders ship in `config/ranks.json`: a Super Mario set, a Zelda set, and the
"Global ranking" set whose top nine rungs are percentiles of the ranked population.
`doctrine:fixtures:load` seeds them, but fixtures only run on an empty database, so an
existing board takes them from:

```bash
php bin/console app:ranks:sync --dry-run
php bin/console app:ranks:sync
```

Sets are matched by name and their rungs replaced; the set row itself is kept, so every
member who has chosen that ladder keeps their choice. A set that is not in the JSON is
left alone, which makes this safe on a board with ladders of its own.

Percentile rungs stay at an unreachable threshold until `app:board:maintenance`
recomputes them from the actual population; on a board with nobody above 1,000 posts
they simply never apply, which is correct.

Rank labels may contain an `<img>`; nearly every rung the original shipped was a sprite
stacked over its name. The `src` has to be a path under `/images/`, so a badge that
renders on every post cannot become an outbound request to somewhere else.

---

## Importing an existing board

```bash
php bin/console app:board:import-legacy --dsn="mysql://user:pass@host/oldboard" --dry-run
php bin/console app:board:import-legacy --dsn="mysql://user:pass@host/oldboard"
php bin/console app:board:recount
```

Run the dry run first; it reports what would be imported without writing anything.

**Members keep their passwords.** The old `users.password` column holds a raw md5.
Those are imported with a `passwordLegacyMd5` flag, verified once on the member's next
sign-in, and transparently rehashed with the modern hasher at that moment. Nobody has
to reset anything, and no new md5 can ever be created - the legacy hasher's `hash()`
throws if anything tries.

Two things do not survive the import:

- **Thread post icons.** The original stored a full URL and rendered it unescaped,
  which made the icon field a stored-XSS vector on every forum listing. Icons are
  dropped and can be reassigned from the shipped set.
- **Timezone offsets** become named zones. An offset cannot identify a zone - it
  carries no daylight-saving rules - so each one maps to a representative region zone
  at that standard offset. Members are invited to correct it, and the profile form
  detects their real zone from the browser.

---

## Tests

```bash
php bin/console --env=test doctrine:database:create
php vendor/bin/phpunit
```

The schema and fixtures are built by the suite itself, so only the empty database has
to exist. It is a separate database from the live one - Doctrine appends `_test` to
the name in the test environment.

Coverage needs PCOV or Xdebug, which are not in a stock PHP install:

```bash
php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text
```

See the README for how the suite is arranged and what it is guarding.
