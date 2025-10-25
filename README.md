# Uptime (Grav plugin)

Minimal `/uptime` endpoint returning JSON with status/env/time **and host + container uptime**.
Admin-configurable, env-overridable, and i18n-ready.

## Install

```bash
bin/gpm install uptime
```

## Configure

Admin → Plugins → **Uptime** or edit `user/config/plugins/uptime.yaml`.

Environment variable overrides:

```
HEALTH_ROUTE=/uptime
HEALTH_STATUS=ok
HEALTH_SERVICE=myapp
APP_ENV=staging
TZ=Europe/Paris
HEALTH_EXTRA_JSON={"git_sha":"$GIT_SHA","version":"1.2.3","build":"env:BUILD_ID|dev"}
```

## Endpoint

`GET /uptime` → `application/json`

```json
{
  "status": "ok",
  "service": "grav",
  "env": "prod",
  "time": "2025-10-25T12:00:00+02:00",
  "uptime_host": {
    "seconds": 34252,
    "boot_iso": "2025-10-25T02:29:08+02:00"
  },
  "uptime_container": {
    "seconds": 12,
    "started_iso": "2025-10-25T11:59:48+02:00"
  }
}
```

> In Docker, `uptime_container` reflects PID 1 (the container). `uptime_host` is the kernel uptime.

## i18n

All strings are in `languages.yaml` (`en`, `fr`).
Blueprint description uses `PLUGIN_UPTIME.DESCRIPTION`.

## License

MIT — © Dennis de Best, 2025
