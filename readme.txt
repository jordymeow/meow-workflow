=== Meow Workflow - AI Agents & No-Code Automation for WordPress ===
Contributors: TigrouMeow
Tags: workflow, automation, ai, no-code, flows
Donate link: https://www.patreon.com/meowapps
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visual, no-code automation for WordPress. Draw flows on a canvas, wire your plugins and AI together, and let AI build the missing pieces.

== Description ==

**Meow Workflow adds a visual node-graph editor to WordPress.** Pick a trigger — a WordPress event, a schedule, a webhook, or a manual "Test once" — then drag steps onto a canvas, connect them, and watch the flow run.

Unlike Zapier, Make or n8n, there is no connector catalog to wait for. Meow Workflow runs **inside WordPress**, so it already reaches your posts, users, comments and plugins. Need something we don't ship? Describe it to [Code Engine](https://wordpress.org/plugins/code-engine/)'s AI and it writes the function — instantly a step you can wire in. No catalog, no waiting.

== What you can build ==

* Email yourself when a post is published or a comment comes in.
* Turn an RSS feed into AI-written draft posts on a schedule.
* Generate an SEO excerpt with AI every time a post is saved.
* Send a thank-you note when a WooCommerce order completes.
* Branch a flow on a value, loop over a list, retry on failure — all visually.

== Steps come from three places ==

* **Always available** — Core (logic, branching, loops, delays, email, HTTP) and WordPress (posts, users, comments, media).
* **From your plugins** — any plugin that supports Meow Workflow adds its triggers and actions automatically, and removes them when deactivated. The Meow family ships first-class: AI Engine (text, image, vision, JSON), SEO Engine (scoring, titles, excerpts), Code Engine (your own functions), Social Engine (posting).
* **Written by you or AI** — for anything else, a Code Engine function becomes a step the moment it's active.

== Build it with AI ==

Don't want to draw it yourself? Describe what should happen in plain English — "when an order comes in, email me a summary" — and (with [AI Engine](https://wordpress.org/plugins/ai-engine/) installed) it assembles the workflow from your installed steps.

== Safe by design ==

Editing a workflow never disturbs the live one: your changes are auto-saved to a **draft**, and the running version keeps using the last **published** copy until you hit Publish. Test once always runs the draft, against real data from your site, so you can experiment on a live automation without risk.

== For developers ==

Add your own actions and triggers by hooking the `mwflow_register_integration` filter — no changes to Meow Workflow required. The bundled integrations under `classes/integrations/` are the reference implementations. Nothing phones home: AI steps call your locally installed AI Engine, and the HTTP action only reaches public URLs you configure.

== Installation ==

1. Install Meow Workflow from the Plugins screen (or upload the ZIP under Plugins → Add New → Upload Plugin).
2. Activate it.
3. Open **Meow Apps → Workflow**.
4. Click **New workflow** and start from an example, describe one to AI, or build from a blank canvas. The Setup Assistant walks you through the rest.

Optional: install [AI Engine](https://wordpress.org/plugins/ai-engine/) for AI steps and Build-with-AI, [Code Engine](https://wordpress.org/plugins/code-engine/) to turn your own functions into steps, and [SEO Engine](https://wordpress.org/plugins/seo-engine/) for SEO steps.

== Frequently Asked Questions ==

= Do I need any other plugin to use it? =

No. Core and WordPress steps work on their own. Other engines (AI, SEO, Code, Social) and compatible plugins simply add more steps when they're active.

= How is this different from Zapier, Make or n8n? =

Those run on someone else's servers and make you wait for each connector to be built. Meow Workflow runs inside your WordPress, already reaches everything WordPress can, and — with Code Engine — lets AI write any step that's missing. Your data never leaves your site.

= Is it safe to edit a workflow that's already running? =

Yes. Edits are auto-saved as a draft while the live triggers keep running the last published version. Nothing goes live until you click Publish, and pausing a workflow stops its trigger immediately.

= Can I add my own actions and triggers? =

Yes — hook the `mwflow_register_integration` filter from your own plugin, or write a function in Code Engine and it appears in the step picker instantly.

= Does it send my data anywhere? =

No. AI steps use your own installed AI Engine (and your own API keys). The HTTP Request action only reaches public URLs you specify — private and internal addresses are blocked.

== Screenshots ==

1. The visual canvas — drag steps onto the board and connect them. Here a Code Engine function reads the temperature, AI Engine classifies it, and Branch By Value routes to the right action.
2. Configure each step inline — set the recipient, subject and body of an email, pulling data from earlier steps with {{ references }}.
3. "Test once" runs the draft against real data from your site — each step lights up green as it succeeds, before anything goes live.
4. Schedule a workflow — this weekly content brief runs every Monday, gathers recent posts, and writes a summary with AI Engine.

== Changelog ==

= 0.1.5 (2026/09/03) =
* Add: Validate a workflow before publishing, with a list of issues to fix.
* Add: Email the admin when a live workflow fails.
* Add: Insert new steps between existing ones, with undo and redo in the editor.
* Update: Delay is now a Wait step that parks live runs for minutes, hours or days.
* Update: Webhooks accept form-encoded requests and reject GET unless it's explicitly allowed.
* Fix: A step with two parent steps no longer runs twice.

= 0.1.4 (2026/09/02) =
* Fix: The editor no longer overwrites in-progress edits after an auto-save.
* Add: The inspector shows the selected step's last run output and error at the top.
* Add: References accept bracket indexes, and the Insert data menu lists nested fields of JSON outputs after a test run.
* Fix: JSON objects are converted to text when used in text fields.
* Fix: The unpublished-changes flag ignores derived trigger fields, so untouched flows show as live.

= 0.1.3 (2026/09/02) =
* Fix: Webhook URL now displays in the editor.
* Fix: Webhook payload fields are accessible as both `trigger.field` and `trigger.body.field`.
* Fix: Missing-input errors now name the reference that came back empty.
* Fix: Webhook token generation no longer overwrites unsaved flow changes.

= 0.1.2 (2026/08/31) =
* Fix: Scheduled workflows no longer stall before the final node.
* Fix: Async runs now process a full batch of nodes per cron pass instead of a single node.
* Add: HTML format option for the Send Email node.
* Add: Categories, date and edit link to post node outputs.
* Update: Synced the shared common library with the new dashboard board, and updated the tested and PHP requirements.
* 🎵 Discuss with others about Meow Workflow on [the Discord](https://discord.gg/bHDGh38).
* 🌴 Keep us motivated with [a little review here](https://wordpress.org/support/plugin/meow-workflow/reviews/). Thank you!
* 🥰 If you want to help us, check our [Patreon](https://www.patreon.com/meowapps). Thank you!

= 0.1.1 (2026/06/27) =
* Update: Moved Workflow Engine under the Meow Apps menu instead of having its own top-level menu entry.
* Update: Restored the shared common framework used across Meow plugins.
* 🎵 Discuss with others about Meow Workflow on [the Discord](https://discord.gg/bHDGh38).
* 🌴 Keep us motivated with [a little review here](https://wordpress.org/support/plugin/meow-workflow/reviews/). Thank you!
* 🥰 If you want to help us, check our [Patreon](https://www.patreon.com/meowapps). Thank you!

= 0.1.0 =
* Initial release.
* Visual node-graph editor with live "Test once" and per-step output previews.
* Triggers: manual, schedule (WP-Cron), webhook, WordPress hook, RSS.
* Logic steps: condition, branch-by-value, for-each loop, delay, set variable, random, send email, HTTP request, log.
* Built-in integrations: WordPress core, AI Engine, SEO Engine, Code Engine, Social Engine, WooCommerce.
* Build-with-AI authoring (requires AI Engine).
* Draft/Publish model, error handling per step (stop / continue / retry / failure branch), import & export.
