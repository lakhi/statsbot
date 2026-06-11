---
name: deploy-frontend
description: Build, publish, and deploy the Angular frontend to the live ZID OpenShift webspace (statsbot.univie.ac.at). Use when shipping frontend changes to production. Pull-based — publishes a GitHub release, then the user pastes one line in the pod Terminal.
---

# deploy-frontend

Ships the Angular frontend to the live webspace. The OpenShift API and SFTP are firewalled, so
deploy is **pull-based**: we publish the build to a GitHub release and the container pulls + applies
it via a one-line paste in the OpenShift web Terminal. A human approves every live write (Option B).

**Prerequisite (one-time):** `webspace-deploy.sh` + `webspace-rollback.sh` must already be in
`/var/www/` on the pod. If they aren't, do "One-time pod setup" below first.

## Steps

1. **Build + publish.** From the repo root:
   ```
   bash scripts/publish-frontend.sh
   ```
   This runs `npm ci && npm run build`, sanity-checks the output, packages it, and uploads it to the
   `frontend-latest` GitHub release. **If the build fails, STOP and surface the first error — do not
   auto-fix.** Capture the printed `sha256`. (The `gh release` upload is an outward publish; it may
   prompt for approval.)

2. **Hand off the apply step.** Show the user this reminder **before** the paste line:
   > 1. Connect to the **U:Wien VPN**.
   > 2. Open the pod Terminal:
   >    `https://console-openshift-console.web.univie.ac.at/k8s/ns/lehrprojeg67/pods/zid-webproject-7f5f77ffb7-zfvt8/terminal`
   >    The pod name changes when the pod restarts — if that 404s, open
   >    `https://console-openshift-console.web.univie.ac.at/k8s/ns/lehrprojeg67/pods`
   >    and click the running `zid-webproject-…` pod → **Terminal** tab.
   > 3. Paste this one line:

   Then give the exact line, with the `sha256` from step 1:
   ```
   EXPECT=<sha256> bash /var/www/webspace-deploy.sh
   ```

3. **Verify** once the user pastes the output back:
   - `deploy.log` tail shows `backup created` and `deploy done: now serving main-….js`.
   - In-container `grep -o 'main-[A-Z0-9]*\.js' /var/www/html/index.html` matches the new bundle.
   - Ask the user to hard-refresh `https://statsbot.univie.ac.at` (⌘⇧R or a private window) and confirm the change.

4. **If anything looks wrong**, have them roll back:
   ```
   bash /var/www/webspace-rollback.sh        # restore the newest backup
   bash /var/www/webspace-rollback.sh -l     # list available backups
   ```

## One-time pod setup
In the pod Terminal (once — uses the container's GitHub egress):
```
curl -fsSL https://raw.githubusercontent.com/lakhi/statsbot/main/scripts/webspace-deploy.sh   -o /var/www/webspace-deploy.sh
curl -fsSL https://raw.githubusercontent.com/lakhi/statsbot/main/scripts/webspace-rollback.sh -o /var/www/webspace-rollback.sh
chmod +x /var/www/webspace-deploy.sh /var/www/webspace-rollback.sh
```

## Notes
- `webspace-deploy.sh` is **idempotent** (re-pasting the same build = no-op) and **sha-gated**
  (`EXPECT` must match the downloaded asset, else it aborts before touching `html/`).
- `.htaccess` and `lehrprojekt-backend/` are never in the build tarball, so deploys never touch them.
- To later make this fully automatic (Option A), add a webspace-admin cronjob:
  `*/5 * * * * /bin/bash /var/www/webspace-deploy.sh` — no script changes needed (it falls back to the
  published `.sha256` when `EXPECT` is unset).
