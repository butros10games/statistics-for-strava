# Updates

When a new version of the app is released, you need to pull the latest Docker image:

```bash
> docker compose pull # if available pull a new image
> docker compose up -d # start a new container using the compose config and the new pulled image.
```

After that, run the unified update command again to pull in the newest Garmin and Strava data and rebuild the app:


```bash
> docker compose exec daemon bin/console app:update-data
```

