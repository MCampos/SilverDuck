A WordPress plugin that automatically checks comments with an LLM and can approve, spam, or hold them.

## Key Features

- **LLM classification pipeline** using OpenRouter (primary) with configurable model, threshold, and heuristics to reduce API calls.
- **Groq fallback support**: provide a Groq API key/model to continue classifying when OpenRouter rate limits or is unavailable.
- **Admin tooling** (`Silver Duck` → `Settings`, `Tools`, `Logs`) for configuration, manual tests, batch rechecks, log browsing, and retention.
- **Audit logging** with ThickBox previews that pretty-print JSON responses from both providers.

## Groq Fallback Setup

1. Install and activate the plugin as usual.
2. In `Silver Duck` → `Settings`, expand the **Groq Fallback** section.
3. Check **Enable Groq fallback**, paste your Groq API key, and provide a model slug (e.g. `llama-3.1-8b-instant`).
4. Save settings. When OpenRouter is rate-limited, the plugin will automatically retry with Groq.

Log entries will note which provider handled a classification in the `Model` column (`groq:<model>` when Groq is used).
