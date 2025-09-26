# Silver Duck Comment Classifier

Silver Duck is a WordPress plugin that routes incoming comments through a modern LLM to decide whether they should be approved, held for moderation, or marked as spam. The plugin prioritises OpenRouter models, automatically falls back to Groq when available, and keeps a searchable audit trail of every decision it makes.

## Features

- **Two-stage defence** – fast heuristics (link counts, blacklists, disposable emails) run before any API call, saving tokens and time.
- **LLM classification** – configurable OpenRouter model, confidence threshold, and behaviour for spam/ham decisions.
- **Groq fallback** – optional provider that automatically takes over if OpenRouter is rate limited or unreachable.
- **Transparent logging** – browse structured logs with pretty-printed JSON responses, filter by error, model, decision, date, or comment ID.
- **Admin toolkit** – test ad-hoc comments, re-run batches of pending comments, and purge logs from the Tools page.
- **Safety oriented** – honours WordPress capability checks, nonces, autoload settings for secrets, and supports cron-driven log retention.

## Requirements

- WordPress 5.8 or newer.
- PHP 7.4+.
- OpenRouter account and API key (required for primary provider).
- Optional Groq account and API key (used only when you enable fallback).

## Installation

1. Download or clone this repository into your WordPress `wp-content/plugins/` directory. You can also build a ZIP from GitHub Releases and upload it via **Plugins → Add New → Upload**.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. A new top-level **Silver Duck** menu will appear in the WordPress admin sidebar.

## Configuration

Navigate to **Silver Duck → Settings** to configure the classifier.

### 1. Core settings

- **Enable classifier** – turn the system on/off without deactivating the plugin.
- **OpenRouter API Key** – paste your key (the field is write-only; leave blank to keep the saved key).
- **Model** – any OpenRouter model slug (defaults to `meta-llama/llama-3.2-3b-instruct:free`).
- **Spam threshold** – confidence level (0–1) that determines when LLM “spam” responses are auto-acted upon.
- **When labeled spam** – choose `Mark as Spam` or `Hold for Moderation` as the automatic action.
- **API timeout** – seconds to wait for the provider before failing safe.
- **Force "Spam" when LLM says spam** – skips the threshold checks and always follows a spam verdict.
- **Auto-approve when LLM says valid** – automatically approves ham/valid results.
- **Also classify comments from logged-in users** – extend LLM checks to authenticated commenters.

### 2. Content heuristics

Configure link-count limits and term blacklists that short-circuit common spam before hitting the API.

### 3. Author field checks

Enable disposable-email detection, domain blacklists, and author name phrase filters.

### 4. Post context

Send a trimmed summary of the post content to the LLM so it can judge topical relevance. Adjust maximum characters (default 2000).

### 5. Groq fallback

1. Check **Enable Groq fallback**.
2. Paste your Groq API key.
3. Provide a Groq model slug (e.g. `llama-3.1-8b-instant`).
4. Save. The plugin will now retry with Groq when OpenRouter responds with 429s or similar availability errors. Logs display `groq:<model>` to indicate the provider used.

### 6. Maintenance

Set log retention (days). A daily cron purges entries older than the configured value.

## Daily Usage Workflow

1. **Live comments** – Silver Duck runs automatically when comments are posted. Decisions honour the heuristics, OpenRouter, and optional Groq fallback settings.
2. **Tools page** (`Silver Duck → Tools`):
   - **Test a Comment** – paste sample text to see an immediate classification and inspect the log entry.
   - **Recheck Pending Comments** – re-run the classifier against items in the moderation queue (non-destructive; it only logs results).
   - **Purge Logs** – manual purge based on retention rules.
3. **Logs page** – filter by decision, error, model, comment ID, or date range. Click **View** in the response column to inspect the formatted JSON payload returned by the provider.

## Troubleshooting

- **“Missing OpenRouter API key” error** – revisit **Settings** and ensure the key field is filled before saving.
- **Rate limited** – check the logs; Silver Duck stores retry headers and schedules automatic backoff. Consider enabling Groq fallback as a secondary provider.
- **No responses in logs** – new entries now always include JSON (even for errors). If you see `-`, the entry predates version 1.2.10.
- **Cron purge not running** – confirm WP-Cron is enabled or set up an external cron hitting `wp-cron.php`.

## Development

- The plugin has no external PHP dependencies; standard WordPress hooks and APIs are used.
- GitHub Actions builds the distributable ZIP whenever a version tag (e.g. `v1.2.15`) is pushed.
- To contribute, fork the repo, create feature branches, and open pull requests. Keep documentation and changelog entries in sync with feature changes.

## Support

For bugs or feature requests, open an issue on the GitHub repository. Include relevant log excerpts (with API keys redacted) and note whether OpenRouter and/or Groq were active when the issue occurred.
