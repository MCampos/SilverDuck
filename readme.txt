=== Silver Duck ===
Contributors: Matt Campos
Tags: spam, comments, ai, llama, openrouter
Requires at least: 5.8
Tested up to: 6.6
Stable tag: 1.2.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Silver Duck classifies WordPress comments in real time using OpenRouter-hosted LLMs, with an optional Groq fallback provider. The plugin keeps a detailed audit trail, offers batch rechecks, and lets you fine tune heuristics so fewer spam comments reach moderation.

== Description ==
Silver Duck adds an AI-assisted spam defence layer to WordPress comments. Incoming comments are screened by a set of configurable heuristics (links, blacklists, disposable domains) and then scored by an OpenRouter model. When rate limits or outages occur, the plugin can automatically retry with a Groq model if you provide credentials.

The plugin exposes a full admin suite:

* A top-level **Silver Duck** menu with separate **Settings**, **Tools**, and **Logs** pages.
* Write-only secret storage for API keys (autoload disabled).
* Daily cron-driven log retention cleanup.
* ThickBox modals that pretty-print JSON results so you can audit every provider response.

== Features ==
* Customise the OpenRouter model, spam threshold, and automatic action for spam verdicts.
* Optional Groq fallback with its own API key and model selector.
* Pre-flight heuristics for link count, content blacklist, author name, email, and URL checks.
* Post-context summariser sends trimmed page content (configurable length) to the LLM for topical relevance checks.
* Batch recheck tool for comments stuck in moderation and a quick “Test a Comment” widget for experimentation.
* Detailed logs filterable by decision, error, model, comment ID, or date range.
* Cron-based log retention respecting your configured day count.

== Requirements ==
* WordPress 5.8 or newer.
* PHP 7.4 or newer.
* OpenRouter account and API key (required primary provider).
* Optional Groq account and API key (used when enabling fallback).

== Installation ==
1. Download the release ZIP or clone this repository into `wp-content/plugins/silver-duck/`.
2. Activate **Silver Duck Comment Classifier** from the Plugins screen.
3. A new **Silver Duck** menu will appear in the WP admin sidebar.

== Configuration ==
1. Navigate to **Silver Duck → Settings**.
2. In **Core Settings**, enable the classifier, paste your OpenRouter API key, choose a model (default `meta-llama/llama-3.2-3b-instruct:free`), and adjust spam thresholds/actions.
3. Fine-tune **Content Heuristics** (link limit, blacklist phrases) and **Author Field Checks** (disposable email detection, domain/name blacklists).
4. Enable **Post Context** to send a trimmed summary of the post content to the LLM; adjust character limit if needed.
5. (Optional) Expand **Groq Fallback**, tick **Enable Groq fallback**, provide your Groq API key and model slug (e.g. `llama-3.1-8b-instant`). Silver Duck will automatically retry on Groq if OpenRouter responds with 429/5xx errors. Logs show `groq:<model>` when used.
6. Set **Log Retention Days** under Maintenance. A daily cron purge removes older entries.

== Using the Tools Page ==
* **Test a Comment** – paste sample text to immediately see the decision and confidence. Results appear in the Logs table.
* **Recheck Pending Comments** – re-run the classifier across moderated comments in batches (non-destructive; it only logs the decision).
* **Purge Logs** – manually purge logs respecting the configured retention window.

== Logs Page ==
* View the latest classifications with filters for decision, errors, model substring, comment ID, and date range.
* Click **View** in the **Response** column to open a prettified JSON modal containing the provider response, including error metadata.
* Pagination and “Rows per page” controls let you inspect large datasets comfortably.

== Frequently Asked Questions ==
= The logs show "rate_limited" and a blank response. What happened? =
OpenRouter throttled requests. Silver Duck stores retry headers, backs off automatically, and—if Groq fallback is enabled—attempts the classification there. Older log entries from pre-1.2.10 builds may not contain JSON.

= Does enabling Groq fallback disable OpenRouter? =
No. OpenRouter remains the primary provider. Groq is only contacted when OpenRouter fails or returns a 429/5xx response.

= Can I run the classifier for logged-in users? =
Yes. Enable **Also classify comments from logged-in users** in the Core Settings section.

= What happens if the LLMs are unavailable? =
Silver Duck fails safe: it logs the error, leaves the decision as “valid” with confidence 0.5, and avoids auto-approving the comment unless you explicitly force approvals.

== Troubleshooting ==
* Ensure your API keys are valid and not rate limited by checking the Logs page or the provider dashboard.
* If cron purges are not running, verify WP-Cron is enabled or schedule an external cron call to `wp-cron.php`.
* For unexpected behaviour, enable WP_DEBUG and capture logs, then open a GitHub issue with sanitized details.

== Changelog ==
= 1.2.16 =
* Fix Groq fallback trigger when OpenRouter is globally rate limited.
* Expand README instructions for installation, configuration, and troubleshooting.

= 1.2.15 =
* Added Groq fallback provider with dedicated settings and automatic retry when OpenRouter is throttled.
* Enhanced logs to indicate which provider handled each classification.
* Improved JSON formatting and fallback responses for error scenarios.

= 1.2.0 =
* Added blog post context (relevance) with configurable length.
* Retained author-field checks, heuristics, and rate-limit backoff improvements.

= 1.1.0 =
* Added author-field checks (name/email/url) plus disposable domain detection.

= 1.0.0 =
* Initial release.
