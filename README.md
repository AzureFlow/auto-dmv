# Auto DMV

Automatically get alerted via [Pushover](https://pushover.net/) when a new IL DMV appointment becomes available.

### Running with Docker

Copy `src/config.sample.php` to `src/config.php`.

Build:

```bash
docker build -t azureflow/auto-dmv .
```

Run:

```bash
docker run --rm --name dmv -d azureflow/auto-dmv
```