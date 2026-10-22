# Posteria Landing Page

> [!IMPORTANT]
> ### Posteria is no longer actively developed
>
> Posteria has been replaced by **[Marquee](https://getmarquee.now)** — a complete re-write that is faster,
> easier to run, and actively maintained. All new features and fixes happen there.
>
> **[Visit Marquee](https://getmarquee.now)** · **[Marquee on GitHub](https://github.com/jeremehancock/Marquee)**
>
> Posteria remains open source and the existing Docker image still works, but it is unmaintained — no new features,
> fixes, or support. Migrating to Marquee is strongly recommended.
> The Posteria repo is still available at [jeremehancock/Posteria](https://github.com/jeremehancock/Posteria).
>
> **The Find Poster API stays here.** It is not moving to Marquee. It remains on the **posteria.app** domain and is
> actively maintained.
>
> **The legacy API is not.** It still runs, but like Posteria itself it is unmaintained — no new features, fixes, or
> support.

This repository contains the landing page for Posteria, a media poster management system. The page is built with HTML, CSS, and vanilla JavaScript.

Visit [posteria.app](https://posteria.app) to see the landing page in action. It now serves as a notice pointing visitors to Marquee; the original page is archived at [posteria.html](posteria.html) ([live](https://posteria.app/posteria.html)).

## Deployment

posteria.app runs on Coolify from the `Dockerfile` in this repository, on the official `php:8.5-cli` image with
PHP's built-in web server. `router.php` keeps the repository's internal files off the site.

Coolify settings for the app:

- **Build Pack:** Dockerfile
- **Ports Exposes:** 8080
- **Environment variables:** `TMDB_API_KEY`, `FANART_API_KEY`, `TVDB_API_KEY`, `MEDIUX_API_KEY` and `POSTERIA_API_KEY`,
  as before

### Keeping PHP up to date

`php:8.5-cli` is a moving tag, so each fresh build picks up the newest PHP 8.5 patch release. A running container
never updates itself: **redeploy in Coolify to pick up a new PHP release.**

The **PHP currency** workflow (`.github/workflows/php-currency.yml`) checks every Monday that the live site runs the
newest 8.5 release and fails, which sends an email, when it does not. It can also be run by hand from the Actions tab.
GitHub turns scheduled workflows off after 60 days without activity in the repository and emails a warning first;
re-enable it from the Actions tab when that happens.

PHP 8.5 receives security fixes until the end of 2029. Move to a newer line before then by changing the `FROM` line
in the `Dockerfile`. The workflow needs no change: it reads the version line from the live site.

## License

[MIT License](LICENSE)

## AI Disclosure

This project was created with the help of AI.
