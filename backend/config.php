<?php
// Secrets come from Render environment variables - never hard-code the token.
$github_token   = getenv('GITHUB_TOKEN');
$repo           = getenv('GITHUB_REPO') ?: 'aedtpworldawfl/awflwikidataworld';
$branch         = getenv('GITHUB_BRANCH') ?: 'main';

// Browser origin allowed to call the API (the GitHub Pages site).
$allowed_origin = getenv('ALLOWED_ORIGIN') ?: 'https://aedtpworldawfl.github.io';

// Public site URL (used for canonical links, sitemap and llms.txt).
$site_url       = 'https://aedtpworldawfl.github.io/awflwikidataworld';
