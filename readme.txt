=== Silver Duck ===
Contributors: Matt Campos
Tags: spam, comments, ai, llama, openrouter
Requires at least: 5.8
Tested up to: 6.6
Stable tag: 1.2.15
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Classifies comments as spam/ham using OpenRouter’s Llama models (with optional Groq fallback). Includes settings, logs, heuristics (links/blacklists), author field checks (name/email/url), optional blog-post context for relevance, and rate-limit backoff.

== Installation ==
1. Upload the ZIP via Plugins → Add New → Upload.
2. Activate, then go to Settings → Silver Duck and set your OpenRouter API key.

== Changelog ==
= 1.2.15 =
* Added Groq fallback provider with dedicated settings and automatic retry when OpenRouter is throttled.
* Enhanced logs to indicate which provider handled each classification.
* Improved JSON formatting and fallback responses for error scenarios.
