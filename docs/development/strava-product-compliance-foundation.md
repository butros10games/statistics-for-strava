# Strava product and compliance foundation

Last checked against Strava developer/legal docs: 2026-06-28.

This note is the product and compliance baseline for turning Statistics for Strava from a generic stats viewer into a personal training decision dashboard. It is intentionally scoped to this app's current self-hosted Symfony source tree, which already has user accounts, Strava OAuth, manual sync, webhooks, training plans, training-load views, and AI-related features.

Official sources to re-check before implementation or review:

- [Strava API Agreement and API Policy](https://www.strava.com/legal/api_policy)
- [Strava authentication docs](https://developers.strava.com/docs/authentication/)
- [Strava webhook docs](https://developers.strava.com/docs/webhooks/)

## Product direction

Prioritize logged-in, single-athlete decisions over generic public stats:

- Weekly load, consistency, fatigue/readiness flags, goal progress, race-plan drift, PR/context changes, season review, and activity notes.
- The default dashboard should answer "what should I do next?" before "what did I do all time?".
- Avoid public leaderboards, social comparison, multi-user benchmarking, or aggregate insights unless Strava policy and granted scopes explicitly allow the exact use case.
- Keep generated files and exports user-scoped; do not create bulk datasets or analytics products from Strava data.

## Data boundaries

Treat imported Strava data as data for the authenticated athlete's personal dashboard only.

- Store only the scopes needed for the app's visible features.
- Keep activity, route, stream, photo, challenge, gear, and derived training-plan data scoped to the connected athlete/user.
- Do not use Strava data for cross-user ranking, public profiles, benchmarking, embeddings, model training, or product analytics.
- Do not send Strava-derived data to AI/ML providers unless the Strava API Policy allows the specific flow and the user has made an explicit, informed choice.
- Keep logs and support exports free of tokens, raw routes, raw activity payloads, and unnecessary personal data.

## Disconnect, delete, and export

Current behavior:

- `/account/strava/disconnect` revokes the stored Strava refresh token through Strava before deleting the local `AppUserStravaConnection` row.
- Activity delete webhooks are stored for import processing.
- The app can produce a training advisor JSON export, but that export is not a full user data export and must not become an AI ingestion default.

Required gaps before a hosted or review-ready product:

- Add a user-facing "delete local Strava data" flow that removes imported activities, streams, laps, best efforts, segments, routes, images, generated public files, cached API responses, training plans derived from Strava data, and webhook events for the user.
- Add a user-facing export flow for the app's locally stored data, separate from Strava's own account export.
- Process Strava-side deauthorization signals, if delivered through webhook payloads, by deleting the local connection and stopping sync.
- Document the retention window for logs, generated files, and backups; make deletion cover backups according to that policy.
- Add visible copy that "disconnect" revokes future Strava access but is not the same as deleting local imported data until the delete flow exists.

## Privacy policy needs

Before this is operated as a hosted product, publish a privacy policy that covers:

- Data collected from Strava, Garmin/wellness bridges, account registration, logs, manual notes, and generated training plans.
- Why each data category is used and which features depend on it.
- Token storage, token revocation, webhook handling, and how users can revoke access from Strava.
- Retention periods for imported data, generated static files, logs, backups, and exports.
- Export, disconnect, delete, and support contact instructions.
- Subprocessors and optional integrations, especially AI providers, notification providers, geocoding/weather providers, and hosting/storage.
- A clear statement that Strava data is used for the user's own dashboard and not for public leaderboards, ads, data resale, AI training, or multi-user comparison.

## Review-readiness gates

Do not ship new Strava-facing product work until these checks pass:

- OAuth scopes are minimal and documented by feature.
- Disconnect is covered by automated tests and manual QA.
- Delete/export flows exist or the UI clearly states they are not yet available.
- AI features are disabled for Strava-derived data unless legal/product review confirms the flow complies with Strava policy.
- Webhook processing is user-scoped and includes deauthorization handling if Strava sends those events for the configured app.
- The hosted deployment has HTTPS, support contact, privacy policy, and a stable callback URL matching the Strava app configuration.
- Product surfaces are private-by-default and never expose another user's activities, routes, photos, or training state.
