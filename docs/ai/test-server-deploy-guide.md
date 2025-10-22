# Test Server Update Guide

This checklist is for refreshing the phpGRC **test** server after making frontend/backend changes locally.

## 1. Before Deploying
- Confirm local checks are clean:
  - `npm run typecheck`
  - `npm run test -- IdpProviders.test.tsx` (or relevant suite)
  - `npm run build`
- Verify no `.tar.gz` artifacts remain in the repo root (cleanup with `perl -e 'unlink ...'` if needed).

## 2. Push Source Changes
1. Copy edited files directly to the server. You can upload individual files **or** bundle several into an archive for a single transfer:
   ```bash
   # Option A: single file
   sshpass -p 'Newmail1' scp -P 2332 path/to/file administrator@phpgrc.gruntlabs.net:/home/administrator/<file>

   # Option B: archive multiple files (extract on the server before moving)
   tar czf /tmp/phpgrc-payload.tgz path/to/file1 path/to/file2
   sshpass -p 'Newmail1' scp -P 2332 /tmp/phpgrc-payload.tgz administrator@phpgrc.gruntlabs.net:/home/administrator/
   sshpass -p 'Newmail1' ssh -p 2332 administrator@phpgrc.gruntlabs.net "cd /home/administrator && tar xzf phpgrc-payload.tgz"
   ```
2. Move the uploaded files into the project with sudo:
   ```bash
   sshpass -p 'Newmail1' ssh -p 2332 administrator@phpgrc.gruntlabs.net \
     "echo 'Newmail1' | sudo -S mv /home/administrator/<file> /var/www/phpgrc/current/<target>"
   ```

## 3. Rebuild Frontend on the Server
```bash
sshpass -p 'Newmail1' ssh -p 2332 administrator@phpgrc.gruntlabs.net \
  "cd /var/www/phpgrc/current/web && echo 'Newmail1' | sudo -S npm run build"
```

## 4. Publish Bundle to Web Docroot
Copy the newly generated assets into Laravel’s `api/public` directory **without** `--delete` so PHP files remain intact:
```bash
sshpass -p 'Newmail1' ssh -p 2332 administrator@phpgrc.gruntlabs.net \
  "echo 'Newmail1' | sudo -S rsync -av /var/www/phpgrc/current/web/dist/ /var/www/phpgrc/current/api/public/"
```

## 5. (If Needed) Restore API Public Files
If `api/public` is missing `index.php`, `.htaccess`, etc.:
```bash
sshpass -p 'Newmail1' scp -P 2332 -r api/public administrator@phpgrc.gruntlabs.net:/home/administrator/api_public
sshpass -p 'Newmail1' ssh -p 2332 administrator@phpgrc.gruntlabs.net \
  "echo 'Newmail1' | sudo -S rm -rf /var/www/phpgrc/current/api/public && \
   echo 'Newmail1' | sudo -S mv /home/administrator/api_public /var/www/phpgrc/current/api/public"
```
Then repeat step 4 to overlay the latest `dist` assets.

## 6. Restart PHP-FPM (rarely needed)
```bash
sshpass -p 'Newmail1' ssh -p 2332 administrator@phpgrc.gruntlabs.net \
  "echo 'Newmail1' | sudo -S systemctl restart php8.3-fpm"
```

## 7. Validate
- Hit the test server in the browser (CTRL+F5 to bypass cache).
- Check for missing assets or JSON stub responses.
- If UI still shows stale content, repeat steps 3 & 4.

## Notes
- Do **not** use `rsync --delete` when publishing `dist`; it removes Laravel public files.
- Keep local build artifacts out of the repo (`perl -e 'unlink …'` is safe and allowed).
- Credentials: `administrator@phpgrc.gruntlabs.net:2332` with password `Newmail1`.
