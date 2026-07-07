## Troubleshooting

- **Docker Desktop WSL Integration**: `docker` command not found inside the Ubuntu WSL distro
  even after installing Docker Desktop. Fix: Docker Desktop → Settings → Resources → WSL
  Integration → enable toggle for your specific distro (e.g. "Ubuntu") → Apply & Restart.

- **Docker engine paused silently**: Docker Desktop app can appear open while its engine is
  actually paused/stopped, causing every docker command to fail. Check bottom-left status
  in Docker Desktop ("Engine running" vs paused).

- **Docker permission denied on socket**: `docker compose up` failed with
  "permission denied ... docker.sock". Fix: `sudo usermod -aG docker $USER`, then restart
  the terminal session (or `newgrp docker`).

- **Disk space / I/O errors during image pulls**: Low free space on the host drive where
  Docker stores its data caused a `short read` / `Input/output error` mid-pull, especially
  with the large OpenSearch image (~900MB). Fix: relocate Docker's disk image to a drive
  with more space (Settings → Resources → Advanced → Disk image location).

- **OpenSearch container restart-looping**: OpenSearch 2.12+ requires
  `OPENSEARCH_INITIAL_ADMIN_PASSWORD` to be set even when
  `plugins.security.disabled=true`, otherwise it exits immediately. Fix: add the env var
  to the `opensearch` service in docker-compose.yaml.

- **App showing "vendor/autoload_runtime.php not found"**: the volume mount
  (`./:/var/www/html`) overwrites the `vendor/` folder installed during image build.
  Fix: run `docker compose exec php composer install` after the containers are up.
