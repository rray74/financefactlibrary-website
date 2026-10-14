# Finance Fact Library

A PHP + MySQL site structured as a browsable hierarchy of finance facts:
**Category → Subcategory → Subject → Facts**. Each Subject is a single
keyword/search-phrase page (e.g. "2-Year Fixed Mortgage Rates") showing
its full pool of facts. Styled with Tailwind CSS + DaisyUI. Articles/news
are a deliberate later-stage addition — the groundwork exists but isn't
linked from navigation yet.

## How content works

- **`categories`** — top-level sections (Mortgages, Savings, Tax…).
- **`subcategories`** — groupings within a category (e.g. Mortgages →
  Fixed Rate Mortgages, Variable Rate Mortgages).
- **`subjects`** — a single keyword/search-phrase target, e.g.
  "2-Year Fixed Mortgage Rates". Its slug is the fact page's URL
  (`/fact.php?slug=2-year-fixed-mortgage-rates`).
- **`facts`** — the pool of data points belonging to a subject (value,
  unit, source, last-updated date, review cadence). A fact page shows
  every fact in its subject's pool — nothing is hidden or rotated.

**Why no randomization**: an earlier version of this scaffold rotated a
random subset of facts per day for "freshness." That's backwards —
reshuffling which facts are visible doesn't add information, so it earns
no SEO benefit, and it hides some facts from some crawls for no reason.
What genuinely helps is explained below.

**Articles** (`article_templates`, `article.php`, `renderArticleBody()`)
still work — a body can contain `{{fact:key:value}}`-style placeholders
resolved live — but nothing currently links to them. That's intentional;
wire them in whenever you're ready to start the articles/news stage.

## Fact update procedure

This is the actual freshness mechanism: genuinely updating a fact's
`value` and `last_updated` when the real-world figure changes is what
search engines reward — not display tricks. The site supports this with
a review cadence and a dashboard to act on it.

**1. Every fact has its own `review_frequency_days`.** Different data
types go stale at very different rates, so one blanket schedule doesn't
fit:

| Data type                                                  | Suggested cadence | Why                                                  |
| ---------------------------------------------------------- | ----------------- | ---------------------------------------------------- |
| Mortgage/savings rates (best-buy, average)                 | 7 days            | Move weekly in normal markets                        |
| Bank of England base rate                                  | 42 days           | Set at MPC meetings (~every 6 weeks)                 |
| Product fees, typical costs                                | 30 days           | Change occasionally, worth a monthly glance          |
| Statutory allowances (ISA, personal allowance, thresholds) | 180 days          | Fixed for the tax year, but announced at each Budget |

Set this per fact when you add it — it's a column on `facts`, not a
global setting.

**2. `/admin/review.php` lists everything currently overdue**, most
overdue first, with a link to each fact's source and its live page. For
each one, check the source, then use the buttons on that row: **Still
correct** if the figure hasn't moved (this resets its review window), or
enter the new value and **Update** if it has. Updates are recorded in the
fact's history. Never edit values in phpMyAdmin, which would skip the
history.

**3. Admin login on every machine.** The admin pages have their own
login page (`/admin/login.php`). The username and a hash of the password
live in `config/database.local.php`, which is gitignored, so each machine
has its own and they never reach GitHub. To set them up, run this from
the project root on each machine:

```bash
php scripts/make-admin-password.php
```

It asks for a username and password (at least 12 characters, not shown
as you type), then prints two `define()` lines. Paste them into that
machine's `config/database.local.php`. Until they're there, nobody can
log in, so the admin pages are never left open by accident. Logins last
until you log out or after 8 hours without using the admin pages.

**4. Suggested cadence for actually doing the reviews**: check
`/admin/review.php` weekly. Because cadences are staggered by data type,
most weeks it'll be short — mainly rate-type facts — with the annual
allowances only surfacing once a year around the tax-year change.

## Project structure

```
financefactlibrary/
├── config/
│   ├── database.php              # connection logic (PDO)
│   └── database.local.php.example # copy to database.local.php with real creds
├── includes/
│   ├── functions.php             # DB queries, daily-seeded fact selection
│   ├── header.php                # <head>, nav, links to compiled CSS/JS
│   └── footer.php
├── public/                       # <-- point your Hostinger domain here
│   ├── index.php                 # browse categories > subcategories > subjects
│   ├── category.php              # subcategories within a category
│   ├── subcategory.php           # subjects within a subcategory
│   ├── fact.php                  # a subject's full fact pool
│   ├── article.php               # dormant — not linked yet, see "How content works"
│   ├── admin/
│   │   ├── review.php            # facts overdue for review, see "Fact update procedure"
│   │   ├── login.php             # admin login, see "Fact update procedure" step 3
│   │   └── logout.php
│   ├── .htaccess
│   └── assets/css/tailwind.css   # compiled output — see "Node build tooling"
│   └── assets/js/main.js         # compiled output
├── src/
│   ├── input.css                 # Tailwind v4 + DaisyUI v5 source, theme tokens
│   └── main.js                   # JS entry point for esbuild
├── sql/
│   └── schema.sql                # tables + seed data (categories>subcategories>subjects>facts)
├── package.json                  # build tooling only — not used at runtime
└── README.md
```

## Deploying to Hostinger

Hostinger's Web and Cloud hosting plans don't let you change a domain's
document root: it's always `public_html`. But this project's web root is
`public/`, so that `config/` and `includes/` stay out of reach of a
browser. The workaround is to keep the whole repo _next to_
`public_html`, then replace `public_html` with a symlink (a shortcut)
pointing at the repo's `public/` folder:

```
~/domains/yourdomain.com/
├── app/                      # the Git repo (not reachable from the web)
│   ├── config/
│   ├── includes/
│   ├── public/               # <-- what the website actually serves
│   └── ...
└── public_html -> app/public # symlink
```

Hostinger's built-in hPanel Git tool can't be used with this layout,
because it only deploys into `public_html`. Deploying is a `git pull`
over SSH instead, which is just as quick.

### First-time setup (once)

**1. Turn on SSH.** hPanel → Advanced → SSH Access → enable it and note
the host, port and username. Connect from Terminal on your Mac:

```bash
ssh -p PORT USERNAME@HOST
```

**2. Let the server read your GitHub repo.** If the repo is private, the
server needs its own read-only key. On the server:

```bash
ssh-keygen -t ed25519 -C "hostinger-deploy" -N "" -f ~/.ssh/id_ed25519
cat ~/.ssh/id_ed25519.pub
```

Copy the printed key into GitHub → your repo → Settings → Deploy keys →
Add deploy key (leave "Allow write access" unticked).

**3. Clone the repo next to `public_html`.** Replace `yourdomain.com`
and the GitHub path with your own:

```bash
cd ~/domains/yourdomain.com
git clone git@github.com:YOUR-USERNAME/financefactlibrary-website.git app
```

**4. Swap `public_html` for the symlink.** Keep the old folder as a
backup until the site is working:

```bash
mv public_html public_html_old
ln -s app/public public_html
```

**5. Create the database.** hPanel → Databases → MySQL Databases →
create a database and user, and note the details. Then copy your local
database across: export it from MAMP's phpMyAdmin (Quick, SQL) and import
it into the new database with Hostinger's phpMyAdmin. This brings all
content, history and the `schema_migrations` table with it.

**6. Set the server's config.** Create `app/config/database.local.php`
(copy the `.example` file) with the Hostinger database details, plus the
site's final address:

```php
define('SITE_URL', 'https://www.yourdomain.com');
```

This file is gitignored, so it only exists on the server and is never
touched by `git pull`.

**7. Set up the admin login.** See "Fact update procedure", step 3.

**8. Visit the domain.** `/` should redirect to `/uk/`. Once everything
works, delete `public_html_old`.

### Automatic deploys from VS Code (once)

After the first-time setup, every push to `main` deploys the site:
`.github/workflows/deploy.yml` connects to the server over SSH, runs
`git pull` in the `app` folder, then applies any new migrations.

**1. Create a key for GitHub to log in to Hostinger.** On your Mac:

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -N "" -f ~/.ssh/hostinger_deploy
```

**2. Add the public half to Hostinger.** hPanel → Advanced → SSH Access
→ SSH keys → Add SSH key, and paste the output of:

```bash
cat ~/.ssh/hostinger_deploy.pub
```

**3. Add five secrets to GitHub.** Repo → Settings → Secrets and
variables → Actions → New repository secret:

| Secret               | Value                                                                                     |
| -------------------- | ----------------------------------------------------------------------------------------- |
| `HOSTINGER_HOST`     | SSH host, from hPanel → SSH Access                                                        |
| `HOSTINGER_PORT`     | SSH port, from the same page                                                              |
| `HOSTINGER_USER`     | SSH username, from the same page                                                          |
| `HOSTINGER_SSH_KEY`  | the whole private key: `cat ~/.ssh/hostinger_deploy`                                      |
| `HOSTINGER_APP_PATH` | e.g. `/home/u123456789/domains/yourdomain.com/app` (run `pwd` in the app folder over SSH) |

**4. Test it.** Make a small change, then in VS Code's Source Control
panel: Commit, then Sync Changes (or Push). On GitHub, the **Actions**
tab shows the deploy running. A green tick means the live site is
updated; a red cross shows exactly which step failed.

### Deploying updates

Commit and push to `main` from VS Code. That's it: the site updates and
any new migrations run automatically.

Test migrations locally before pushing, since they run on the live
database straight away. CSV imports still run by hand, once the push has
deployed the file, so you choose when content changes go live:

```bash
cd ~/domains/yourdomain.com/app
php scripts/import-facts.php data/imports/your-file.csv
```

If GitHub Actions is ever unavailable, deploy by hand over SSH with
`git pull` in the same folder.

## Automated jobs

### Bank of England mortgage rates (`scripts/fetch-boe-rates.php`)

Fetches the Bank of England's average quoted mortgage rates (2-year fixed
at 75%, 90% and 95% LTV, 5-year fixed at 75%, and the average SVR)
straight from the Bank's statistical database, then updates the matching
facts, or marks them as verified if unchanged. The series it maintains are
listed at the top of the script.

Before writing anything, every figure must pass three checks: the Bank's
own description of the series must match what's expected, the value
must be a plausible rate, and the change since last time must be within a
set limit. Anything failing the last check is **held for review** and
appears at the top of `/admin/review.php` with Approve and Reject buttons.

Run it by hand over SSH, from the `app` folder:

```bash
php scripts/fetch-boe-rates.php --dry-run   # show what would change, write nothing
php scripts/fetch-boe-rates.php             # fetch and apply
```

**Scheduled run (once):** hPanel → Advanced → **Cron Jobs**. Create a
custom cron job with this command:

```
/usr/bin/php /home/u888389356/domains/financefactlibrary.com/app/scripts/fetch-boe-rates.php
```

Set it to run weekly, for example Mondays at 07:00 (`0 7 * * 1`). The Bank
publishes these figures monthly, so most weekly runs simply confirm the
current figures, which keeps their "checked" dates fresh. Every run is
logged in the `cron_runs` table.

### Official source pages (`scripts/check-sources.php`)

Opens every allowlisted source page cited by a published figure (GOV.UK,
HMRC, DWP, the Scottish and Welsh governments, NS&I and so on) and checks
each figure still appears on its page. Figures it finds are marked as
verified. Figures it can't find, and pages that can't be opened, appear
under **Needs a look** at the top of `/admin/review.php`, with Still
correct and Update buttons. It never changes a figure itself: a missing
figure usually means the page now shows a new value, which needs a
person to confirm.

Bank of England data figures are skipped, since the job above checks
those. Text facts (non-numbers) aren't checked.

```bash
php scripts/check-sources.php --dry-run   # report only, write nothing
php scripts/check-sources.php             # check and record results
```

**Scheduled run (once):** add a second cron job in hPanel with this
command, weekly on Mondays at 07:30 (`30 7 * * 1`):

```
/usr/bin/php /home/u888389356/domains/financefactlibrary.com/app/scripts/check-sources.php
```

After a Budget or at the start of a tax year, run it by hand straight
away to see which figures need updating.

## Adding content

All content goes in through the CSV importer, never by editing the
database by hand. That keeps the change history and verification dates
correct.

```bash
php scripts/import-facts.php data/imports/your-file.csv
```

The header comment at the top of `scripts/import-facts.php` documents
every column. In short:

- **New category, subcategory or subject**: give its slug and name on
  the first row that uses it. It's created automatically.
- **Edit an existing page** (name, intro, meta title, description): fill
  in just the cells to change. Blank cells never change anything.
- **New fact or updated value**: a normal row. Unchanged values are
  marked as verified, and real changes are recorded in the fact's
  history.
- **Show a fact on another page**: a row with `link_type` set to `also`.
- **Remove a fact from a page**: `link_type` set to `unlink`.
- **Publish, hide or retire a page**: the `subject_status` column.

Test every CSV locally first, then run the same file on the server
(see "Deploying updates").

## Node build tooling

Node is **build-time only** — it compiles CSS/JS into static files that
PHP then serves. Hostinger never runs Node or npm; nothing about the live
site depends on it.

```bash
nvm use 20          # or any recent LTS
npm install
npm run build        # compiles src/input.css -> public/assets/css/tailwind.css
                      # and bundles src/main.js -> public/assets/js/main.js
npm run watch         # rebuilds both on file change, for local dev
```

- **CSS**: `src/input.css` holds the Tailwind v4 + DaisyUI v5 setup,
  including the `financefactlibrary` theme tokens (ink navy / sage / mustard /
  paper) and the `.fact-card` / `.article-body` custom rules. Edit colors,
  fonts or custom classes there, not in the compiled output.
- **JS**: `src/main.js` is the entry point esbuild bundles and minifies.
  Add imports/code there as the site grows.
- The **compiled output** (`public/assets/css/tailwind.css`,
  `public/assets/js/main.js`) is committed to the repo, not gitignored —
  that way the site works even deploying straight from GitHub without a
  Node step (Option A above). The GitHub Actions deploy (Option B) runs
  `npm run build` automatically before every deploy, so the committed
  files are really a fallback/local-dev convenience there.
- If using Option A, run `npm run build` and commit the result before
  pushing — hPanel's Git deploy won't build anything for you.

# financefactlibrary-website
