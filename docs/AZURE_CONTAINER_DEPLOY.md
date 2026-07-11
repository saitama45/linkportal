# Deploying linkportal to Azure App Service (custom container)

This replaces the built-in PHP runtime + `startup.sh` with **one Docker image**
that runs everything in a single instance: nginx + PHP-FPM, the queue worker, the
scheduler, and the self-hosted **OCR worker** (Python + Tesseract + LibreOffice).
No extra Azure resource is required.

There are two ways to deploy:

- **Path A — GitHub Actions (recommended):** you push code, GitHub builds and
  ships the image automatically.
- **Path B — Manual:** you build and push from your own PC with Docker.

Do the **one-time setup (Part 1)** first, then pick Path A or Path B.

---

## The files that make up the image

| File | Role |
|---|---|
| `Dockerfile` | Builds the image (assets → composer → runtime) |
| `docker/nginx.conf` | Web server on port 8080, 30 MB upload cap |
| `docker/supervisord.conf` | Runs nginx, php-fpm, ocr-worker, queue-worker, scheduler |
| `docker/entrypoint.sh` | Boot tasks + starts supervisord |
| `docker/php.ini` | Upload sizes, memory, OPcache |
| `.dockerignore` | Keeps secrets/deps out of the image |
| `.github/workflows/deploy.yml` | The GitHub Actions pipeline (Path A) |

> `startup.sh` and `web.config` were the OLD built-in-runtime files. They are
> ignored by the image and unused once you deploy the container.

---

# Part 1 — One-time setup (do once)

### 1.1 Get your `APP_KEY`

You need your Laravel app key. If you already have one in your current `.env`,
copy that value (looks like `base64:....`). If not, generate one locally:

```bash
php artisan key:generate --show
```

Save this string — you'll paste it into Azure in step 1.4.

### 1.2 Order of operations (important)

You already have a Web App named **`linkportal`**. It was created as a **PHP code
app**. We are going to switch it to run our **Docker container** instead. But you
can't point it at an image that doesn't exist yet — so the order is:

1. **Build & push the image first** (Part A with GitHub Actions, or Part B
   manually). This puts the image in GitHub Container Registry (`ghcr.io`).
2. **Then** point `linkportal` at that image (step 1.3 below).
3. Set the environment variables (1.4) and storage mount (1.5).

> ⚠️ In **Deployment Center → Settings**, the **Source** dropdown (GitHub,
> Bitbucket, Local Git…) is for deploying **source code** — Azure would try to
> build your PHP itself. **Do not use it. Do not pick "GitHub" there.** It creates
> a competing build that conflicts with our Docker image.

### 1.3 Point `linkportal` at the container image

Do this **after** the image exists in `ghcr.io` (Part A or B).

The image lives in **GitHub Container Registry (ghcr.io)** — free, not an Azure
resource. Choose one of these:

**A) Make the image public (simplest — no credentials needed).**
After the first build, on GitHub open the package (your profile → **Packages →
linkportal**) → **Package settings → Change visibility → Public**.

**B) Keep it private** and give Azure a token to pull it (a GitHub Personal
Access Token, classic, with the `read:packages` scope).

Then wire it up. **Easiest and unambiguous — one Azure CLI command** (find your
resource group on the app's **Overview** page):

```bash
# Switches linkportal from PHP-code mode to container mode and sets the image.
az webapp config container set \
  --name linkportal \
  --resource-group <your-resource-group> \
  --container-image-name ghcr.io/<owner>/<repo>:latest \
  --container-registry-url https://ghcr.io \
  --container-registry-user <github-username> \
  --container-registry-password <PAT-with-read:packages>
# (If the image is PUBLIC, you can omit the --container-registry-user/-password lines.)
```

> ⚠️ **Do NOT use the `Containers (new)` tab's "Add → Sidecar extension /
> Custom container" form.** That is Azure's *sidecar* feature — for adding extra
> helper containers (Redis, AI, telemetry) to an app that is *already* a
> container. Its "Add container" form is fixed to **Type: Sidecar** and will not
> set your app's **main** image. Our design is a single image that already
> contains everything, so use the **CLI command above** to set the main
> container. It also converts the app from PHP-code mode to container mode.

### 1.4 Add the application settings (environment variables)

A container has **no `.env` file** — every value comes from **Configuration →
Application settings**. Click **New application setting** for each row, then
**Save** (the app restarts).

| Setting | Value / note |
|---|---|
| `WEBSITES_PORT` | `8080` (must match nginx) |
| `APP_KEY` | the `base64:...` value from step 1.1 |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://<your-app>.azurewebsites.net` |
| `DB_CONNECTION` | `sqlsrv` |
| `DB_HOST` | your SQL Server host |
| `DB_PORT` | `1433` |
| `DB_DATABASE` | `tashelpdeskdb` |
| `DB_USERNAME` / `DB_PASSWORD` | your DB credentials |
| `QUEUE_CONNECTION` | `database` |
| `SESSION_DRIVER` | `database` (or what you use today) |
| `CACHE_STORE` | `database` (or what you use today) |
| `OCR_SERVICE_URL` | optional — defaults to `http://127.0.0.1:8077` |
| `GHELPDESK_URL` / `GHELPDESK_API_TOKEN` | ghelpdesk integration |
| `LINKPORTAL_URL` / `LINKPORTAL_API_TOKEN` | reverse integration |
| `IMAP_HOST` / `IMAP_PORT` / `IMAP_ENCRYPTION` / `IMAP_USERNAME` / `IMAP_PASSWORD` | only if using email intake |
| `RUN_MIGRATIONS_ON_STARTUP` | `true` for the very first deploy, then change to `false` |

Copy across any other keys your current `.env` uses.

### 1.5 Mount persistent storage for uploaded documents (important)

Uploaded documents are saved to `storage/app/private`. In a container that folder
is **wiped on every restart** unless you mount storage. Do this:

1. Create (or reuse) a **Storage account** → **File share** (e.g. `linkportal-files`).
2. Web app → **Configuration → Path mappings → New Azure Storage Mount**:
   - **Name:** `storage-app`
   - **Storage type:** **Azure Files**
   - **Storage account / Share:** the ones you just made
   - **Mount path:** `/var/www/html/storage/app`
3. **Save** and restart.

> Do **not** set `WEBSITES_ENABLE_APP_SERVICE_STORAGE=true` — it would hide the
> app inside the image. Mount only the path above.

---

# Part A — Deploy with GitHub Actions (recommended)

### A.1 Push this code to GitHub

Make sure the repo (including the new `Dockerfile`, `docker/`, and
`.github/workflows/deploy.yml`) is pushed to GitHub on the `master` branch.

### A.2 Download the App Service "publish profile"

1. Open your web app in the portal.
2. Top toolbar → **Download publish profile** (a `.PublishSettings` file).
3. Open it in a text editor and copy the **entire** contents.

### A.3 Add two GitHub secrets

In GitHub → your repo → **Settings → Secrets and variables → Actions → New
repository secret**. Add:

| Secret name | Value |
|---|---|
| `AZURE_WEBAPP_NAME` | your web app's exact name (e.g. `linkportal`) |
| `AZURE_WEBAPP_PUBLISH_PROFILE` | the full contents of the publish profile file |

(The registry login uses the built-in `GITHUB_TOKEN` — no secret needed for that.)

### A.4 Run it

- Push any commit to `master`, **or** go to the **Actions** tab → **Build and
  deploy linkportal → Run workflow**.
- Watch the run. It builds the image (first build ~10–15 min), pushes it to
  `ghcr.io`, and deploys it to Azure.
- If your GHCR package is private, make sure step 1.3 credentials are set so
  App Service can pull it. (First run creates the package; set its visibility
  or credentials, then re-run/restart.)

From now on, **every push to `master` deploys automatically.**

---

# Part B — Deploy manually (no GitHub Actions)

Requires Docker Desktop installed locally.

```bash
# 1. Log in to GitHub Container Registry
#    (create a PAT with write:packages, use it as the password)
echo <YOUR_PAT> | docker login ghcr.io -u <your-github-username> --password-stdin

# 2. Build (run from the repo root). Replace owner/repo, lowercase.
docker build -t ghcr.io/<owner>/<repo>:latest .

# 3. Push
docker push ghcr.io/<owner>/<repo>:latest

# 4. Point linkportal at it (also flips it from PHP-code mode to container mode)
az webapp config container set \
  --name linkportal --resource-group <your-rg> \
  --container-image-name ghcr.io/<owner>/<repo>:latest \
  --container-registry-url https://ghcr.io \
  --container-registry-user <github-username> \
  --container-registry-password <PAT-with-read:packages>

# 5. Restart
az webapp restart --name linkportal --resource-group <your-rg>
```

---

# Part 2 — First boot & verification (both paths)

1. For the **first** deploy, `RUN_MIGRATIONS_ON_STARTUP=true` (step 1.4) applies
   the database migrations on boot. After it succeeds once, set it back to
   **`false`** and save.
   - Or run migrations manually via **SSH** (portal → your web app → **SSH**):
     `php artisan migrate --force`
2. Open `https://<your-app>.azurewebsites.net` and sign in.
3. Test OCR: open a Document Intake item → **Run OCR** → the fields should
   populate. (The OCR worker is localhost-only, so verify through the UI.)
4. Watch **Log stream** (portal → your web app → **Log stream**) to see the
   processes (nginx, php-fpm, ocr-worker, queue-worker, scheduler) and any errors.

---

# Part 3 — Before you scale to more than one instance

- **Queue worker:** safe on multiple instances (jobs are pulled atomically).
- **Scheduler:** the loop runs on **every** instance, so email intake / overdue
  sweeps would run multiple times. Before scaling out, add `->onOneServer()` to
  the two commands in `routes/console.php` with a shared cache (database/redis).
  Until then, keep the app at **one instance**.
- **OCR worker:** one per instance is fine.

---

## What changed vs. the old built-in runtime

- The queue worker now processes the **`default`** queue (where OCR jobs live).
- The **scheduler** actually runs (email intake + overdue sweeps).
- The **OCR worker** is baked into the image (Tesseract/LibreOffice preinstalled)
  — fast, reproducible cold starts, no install-at-boot.
- The **SQL Server driver** (`msodbcsql18` + `pdo_sqlsrv`) is included.
