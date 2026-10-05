# AWFLWIKIDATAWORLD

Static wiki on GitHub Pages + a PHP "hidden writer" on Render that commits to this repo.

```
index.html  config.json  tree.json  robots.txt  sitemap.xml  llms.txt  .nojekyll
.well-known/{ai.txt,robots.txt}
backend/{api.php,config.php,.env.example,Dockerfile}
render.yaml
```

## 1. GitHub Pages
Push everything to `aedtpworldawfl/awflwikidataworld` (branch `main`), then Settings → Pages → Deploy from branch `main` / root.
Keep `.nojekyll` — without it GitHub ignores the `.well-known` folder.

## 2. GitHub token
Create a fine-grained token limited to this repo with **Contents: Read and write**.

## 3. Render
New → Blueprint → pick this repo (uses `render.yaml`). Set the secret `GITHUB_TOKEN`.
Your API URL will be `https://<service-name>.onrender.com/api.php`.

## 4. Connect the frontend
Either edit `DEFAULT_API` near the top of the script in `index.html`, or paste the URL into the login box (🔐) — it is remembered in that browser.

## Security note
`config.json` is public (that is how the login in `index.html` works), so the password is readable by anyone.
To protect the writer, set `ADMIN_USERNAME` and `ADMIN_PASSWORD` in Render: the API then ignores the values in `config.json`.
(The login box would then need those same values to be entered into `config.json` for the front-end check, or the front-end check can be changed to call the API's `login` action.)
